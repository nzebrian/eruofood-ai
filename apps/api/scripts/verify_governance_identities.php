<?php

declare(strict_types=1);

/**
 * M29-B — are the governance identities real enough to switch anything on?
 *
 * ## What this adds to M29-A
 *
 * M29-A left `.github/CODEOWNERS` inert: every rule commented out, every owner
 * an `<OWNER:...>` token, and a validator that refuses to call review routing
 * configured while any token survives. That was the right place to stop, but it
 * leaves an obvious next failure. Somebody eventually substitutes handles into
 * that file by hand, gets one wrong, uncomments the rules, and the repository is
 * back where M29-A found it — a CODEOWNERS file that reads as configured and
 * resolves to nobody.
 *
 * So substitution gets a gate of its own. This reads the identity configuration,
 * checks it against everything that is locally checkable, and reports one of
 * three states. It will render a CODEOWNERS file from it, but only when told
 * exactly where to put it, and never over the active one.
 *
 * ## What it will not do
 *
 * It does not call GitHub, apply a ruleset, enable protection, push, merge, or
 * overwrite active governance. It cannot report that governance is *active*,
 * because that is a fact about GitHub and this is a file reader. The best
 * outcome available is READY FOR ACTIVATION, which means nothing further is
 * blocked on this repository.
 *
 * ## Usage
 *
 *   php scripts/verify_governance_identities.php
 *   php scripts/verify_governance_identities.php --identities=/path/to/identities.json
 *
 *   # Explicit input, explicit output, never the live file:
 *   php scripts/verify_governance_identities.php \
 *       --identities=.github/governance/identities.json \
 *       --render-codeowners=/tmp/CODEOWNERS.proposed
 *
 * Exit 0 when there are no errors. An unconfigured repository exits 0 — having
 * supplied no identities is the correct state until somebody supplies them.
 */

require __DIR__.'/../vendor/autoload.php';

use EruoFood\Shared\Domain\Governance\ActivationState;
use EruoFood\Shared\Domain\Governance\GovernanceRole;
use EruoFood\Shared\Domain\Governance\IdentityFinding;
use EruoFood\Shared\Domain\Governance\IdentityPolicy;
use EruoFood\Shared\Domain\Governance\OwnershipDeclaration;

// scripts -> api -> apps -> <repo root>.
$repoRoot = dirname(__DIR__, 3);
$governanceDir = $repoRoot.'/.github/governance';

/** @return array<string, string> */
function options(): array
{
    $out = [];

    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $arg, $m) === 1) {
            $out[$m[1]] = $m[2] ?? '';
        }
    }

    return $out;
}

$options = options();

$identitiesPath = $options['identities'] ?? $governanceDir.'/identities.json';
$identitiesExplicit = isset($options['identities']);
$codeownersPath = $options['codeowners'] ?? $repoRoot.'/.github/CODEOWNERS';
$tagsPath = $options['tags'] ?? $governanceDir.'/production-tags-ruleset.json';

/** @return array<mixed> */
function readJsonOrFail(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("not a JSON object: {$path}");
    }

    return $decoded;
}

echo "EruoFood — governance identity verification (M29-B)\n";
echo str_repeat('=', 72), "\n";
echo "Repository root:  {$repoRoot}\n";
echo 'Identities:       '.(is_file($identitiesPath) ? $identitiesPath : $identitiesPath.'  (ABSENT)')."\n";
echo "CODEOWNERS:       {$codeownersPath}\n";
echo "Tag rulesets:     {$tagsPath}\n";

// -- Inputs -------------------------------------------------------------------

$identities = null;

if (is_file($identitiesPath)) {
    try {
        $identities = readJsonOrFail($identitiesPath);
    } catch (Throwable $e) {
        printf("\nFAIL  the identity configuration does not parse  (%s)\n", $e->getMessage());
        exit(1);
    }
}

// A file *named* like the example is refused as an active configuration even if
// somebody has stripped the "_example" marker out of it. Two independent
// signals, because this is the mistake with the longest feedback loop: nothing
// breaks until a real pull request needs a real reviewer.
if ($identities !== null && str_contains(basename($identitiesPath), '.example.')) {
    echo "\nFAIL  the example file is being used as the active identity configuration\n";
    echo "      ({$identitiesPath})\n\n";
    echo "      Copy it to identities.json, remove \"_example\", and substitute real handles.\n";
    exit(1);
}

$codeownersBody = is_file($codeownersPath) ? (string) file_get_contents($codeownersPath) : '';

if (! is_file($codeownersPath)) {
    echo "\nFAIL  CODEOWNERS is missing — nothing was verified\n";
    exit(1);
}

try {
    $tagDoc = readJsonOrFail($tagsPath);
} catch (Throwable $e) {
    printf("\nFAIL  the tag ruleset artifact does not parse  (%s)\n", $e->getMessage());
    exit(1);
}

$tagRulesets = [];

foreach (is_array($tagDoc['rulesets'] ?? null) ? $tagDoc['rulesets'] : [] as $rs) {
    if (is_array($rs)) {
        $tagRulesets[] = $rs;
    }
}

// One place the repository identity is written down: the artifact that already
// declares which repository it applies to.
$appliesTo = is_string($tagDoc['_meta']['applies_to'] ?? null) ? $tagDoc['_meta']['applies_to'] : '';
$repositoryOwner = explode('/', $appliesTo)[0] ?? '';

// -- Ownership mode -----------------------------------------------------------

// Read before anything is judged: whether FINANCE naming the repository owner
// is a defect or a recorded deferral depends entirely on how many humans there
// are, and that is a declared fact rather than something inferable from files.
$ownershipPath = $governanceDir.'/ownership.json';
$ownershipDoc = null;

if (is_file($ownershipPath)) {
    try {
        $ownershipDoc = readJsonOrFail($ownershipPath);
    } catch (Throwable $e) {
        printf("\nFAIL  the ownership declaration does not parse  (%s)\n", $e->getMessage());
        exit(1);
    }
}

$ownership = OwnershipDeclaration::fromArray($ownershipDoc);

echo "\n0) Governance ownership\n";

foreach ($ownership->mode->summaryLines() as $line) {
    printf("  %s\n", $line);
}

printf("  Repository owner:        %s\n", $ownership->repositoryOwner === '' ? '(undeclared)' : $ownership->repositoryOwner);
printf("  Human participants:      %s\n", implode(', ', $ownership->humanParticipants) ?: '(none declared)');
printf("  Ruleset that applies:    %s\n", $ownership->mode->mainRulesetArtifact());

foreach ($ownership->findings as $finding) {
    printf("  %-7s %s  %s\n", strtoupper($finding->severity->value), $finding->code, $finding->summary);
    printf("          -> %s\n", $finding->remedy);
}

// -- Evaluate -----------------------------------------------------------------

$assessment = (new IdentityPolicy($repositoryOwner, $ownership->mode))->evaluate($identities, $codeownersBody, $tagRulesets);

echo "\n1) Identity configuration\n";

if ($identities === null) {
    echo "  ABSENT  no active identity configuration at {$identitiesPath}\n";
    echo '  PASS    the shipped example is present  ('.(is_file($governanceDir.'/identities.example.json') ? 'identities.example.json' : 'MISSING').")\n";
} else {
    printf("  PRESENT %s\n", $identitiesPath);
}

foreach (GovernanceRole::cases() as $role) {
    $handles = $assessment->resolved[$role->value] ?? null;

    printf(
        "  %-10s %-14s %s\n",
        $handles === null ? 'UNRESOLVED' : 'RESOLVED',
        $role->value,
        $handles === null ? '—' : implode(' ', $handles),
    );
}

echo "\n2) Findings\n";

if ($assessment->findings === []) {
    echo "  none\n";
}

foreach ($assessment->findings as $finding) {
    printf("  %-7s %s  %s\n", strtoupper($finding->severity->value), $finding->code, $finding->summary);
    printf("          -> %s\n", $finding->remedy);
}

echo "\n3) Tag governance\n";

$creation = null;
$immutability = null;

foreach ($tagRulesets as $rs) {
    $types = array_column(is_array($rs['rules'] ?? null) ? $rs['rules'] : [], 'type');

    if (in_array('creation', $types, true)) {
        $creation = $rs;
    }
    if (array_intersect(['deletion', 'non_fast_forward', 'update'], $types) !== []) {
        $immutability = $rs;
    }
}

printf(
    "  %s  creation is restricted by a dedicated ruleset  (%s)\n",
    $creation !== null ? 'PASS' : 'FAIL',
    is_string($creation['name'] ?? null) ? $creation['name'] : 'absent',
);
printf(
    "  %s  immutability is a separate ruleset with bypass_actors=[]  (%s)\n",
    $immutability !== null && ($immutability['bypass_actors'] ?? null) === [] ? 'PASS' : 'FAIL',
    is_string($immutability['name'] ?? null) ? $immutability['name'] : 'absent',
);
printf(
    "  %s  release actors are configured for tag creation\n",
    isset($assessment->resolved[GovernanceRole::ReleaseActor->value]) ? 'PASS' : 'PENDING',
);

echo "\n4) Not provable here — EXTERNAL / ADMIN REQUIRED\n";

foreach ($assessment->externalRequirements() as $requirement) {
    printf("  EXTERNAL / ADMIN REQUIRED  %s\n", $requirement);
}

// -- Optional rendering -------------------------------------------------------

if (isset($options['render-codeowners'])) {
    echo "\n5) CODEOWNERS rendering\n";

    $exit = renderCodeowners(
        $options['render-codeowners'],
        $identitiesExplicit,
        $assessment->state,
        $assessment->resolved,
        $codeownersBody,
        $repoRoot,
        $codeownersPath,
    );

    if ($exit !== 0) {
        exit($exit);
    }
}

// -- Result -------------------------------------------------------------------

// Ownership errors are governance errors: a declaration naming a mode nobody
// implemented, or an assistant as a participant, must fail the run rather than
// be printed above and then forgotten in the exit code.
$errors = array_merge($ownership->errors(), $assessment->errors());
$warnings = $assessment->warnings();

echo "\n", str_repeat('=', 72), "\n";
printf(
    "STATE: %s — %s\n",
    strtoupper(str_replace('_', ' ', $assessment->state->value)),
    $assessment->state->summary(),
);
printf("       %d error(s), %d warning(s), %d role(s) unresolved\n", count($errors), count($warnings), count($assessment->unresolvedRoles));

if ($assessment->state === ActivationState::ReadyForActivation) {
    echo "\nREADY FOR ACTIVATION means nothing further is blocked on this repository.\n";
    echo "It does not mean the repository is protected. Every item in section 4 is\n";
    echo "still outstanding, and each one is a fact about GitHub that no file here\n";
    echo "can establish. See .github/governance/APPLY_GOVERNANCE.md.\n";
}

if (! $ownership->mode->supportsIndependentReview()) {
    echo "\nIndependent human review is NOT ACTIVE on this repository. That is a\n";
    echo "declared deferral under SOLE_OWNER mode, not a passed check. Nothing above\n";
    echo "should be read as evidence that a second person reviewed anything.\n";
}

exit($errors === [] ? 0 : 1);

/**
 * Write a proposed CODEOWNERS somewhere explicit.
 *
 * Generation is the dangerous half of this script, so it is fenced four ways:
 * the input must be named explicitly, the output must be named explicitly, the
 * output may never be the live CODEOWNERS, and an existing file is never
 * overwritten. There is no `--force`. Governance that a script can silently
 * rewrite is not governance, and the recovery from "it regenerated the file
 * wrong" is a code review nobody knew they needed to do.
 *
 * @param array<string, list<string>> $resolved
 */
function renderCodeowners(
    string $target,
    bool $identitiesExplicit,
    ActivationState $state,
    array $resolved,
    string $template,
    string $repoRoot,
    string $activeCodeowners,
): int {
    if (! $identitiesExplicit) {
        echo "  REFUSED  --render-codeowners requires --identities to be given explicitly\n";
        echo "           Rendering from a defaulted path makes the input invisible in the\n";
        echo "           command that produced the output.\n";

        return 1;
    }

    if ($target === '') {
        echo "  REFUSED  --render-codeowners needs an output path\n";

        return 1;
    }

    if ($state !== ActivationState::ReadyForActivation) {
        printf("  REFUSED  state is %s, not ready_for_activation\n", $state->value);
        echo "           Rendering now would produce a CODEOWNERS naming owners that do not\n";
        echo "           resolve — which is the defect M29-A was opened to remove.\n";

        return 1;
    }

    // Two files are off limits, not one. The derived .github/CODEOWNERS is the
    // obvious case; the file this run was TOLD to treat as active
    // (--codeowners=) is the one that was actually read as the template, and
    // rendering over it would overwrite the input with output derived from it.
    // Until M37 only the derived path was protected, so
    // `--codeowners=X --render-codeowners=X` would have written X.
    $protected = [$repoRoot.'/.github/CODEOWNERS', $activeCodeowners];
    $resolvedTarget = realpath(dirname($target));
    $resolvedTarget = $resolvedTarget === false ? dirname($target) : $resolvedTarget;
    $absoluteTarget = $resolvedTarget.'/'.basename($target);

    $collides = false;
    foreach ($protected as $candidate) {
        if ($absoluteTarget === $candidate || realpath($candidate) === $absoluteTarget) {
            $collides = true;
        }
    }

    if ($collides) {
        echo "  REFUSED  will not write to the active .github/CODEOWNERS\n";
        echo "           Render elsewhere, read the diff, and commit it deliberately.\n";

        return 1;
    }

    if (file_exists($absoluteTarget)) {
        printf("  REFUSED  %s already exists\n", $absoluteTarget);
        echo "           There is no --force. Delete it yourself if that is what you meant.\n";

        return 1;
    }

    $lines = [
        '# GENERATED by apps/api/scripts/verify_governance_identities.php',
        '# Source of owners: .github/governance/identities.json',
        '#',
        '# Review this diff before committing it. A generated CODEOWNERS is still a',
        '# claim about who is accountable for the money-moving paths.',
        '#',
        '# Verify after committing:',
        '#   gh api /repos/nzebrian/eruofood-ai/codeowners/errors | jq \'.errors | length\'   # 0',
        '',
    ];

    foreach (explode("\n", $template) as $line) {
        $hasToken = preg_match('/<OWNER:[A-Z_]+>/', $line) === 1;

        if (! $hasToken) {
            $lines[] = rtrim($line);

            continue;
        }

        // Uncomment the rule and substitute. Ordering is inherited from the
        // template untouched: CODEOWNERS resolves last-match-wins, so the
        // catch-all must stay first and /.github/CODEOWNERS must stay last.
        $rule = preg_replace('/^\s*#\s?/', '', rtrim($line)) ?? '';

        $rule = preg_replace_callback(
            '/<OWNER:([A-Z_]+)>/',
            static fn (array $m): string => implode(' ', $resolved[$m[1]] ?? [$m[0]]),
            $rule,
        ) ?? '';

        $lines[] = $rule;
    }

    $body = implode("\n", $lines)."\n";

    // Check the output with the same rules that judge the input, rather than a
    // second implementation that can drift from it. Scanning for the literal
    // string `<OWNER:` would also match the template's own prose explaining what
    // the tokens are — a comment, owning nothing. What must not survive is an
    // unresolved owner on an *active* rule, which is precisely what the policy
    // already knows how to find.
    $selfCheck = (new IdentityPolicy(''))->evaluate(null, $body, []);

    $unresolved = array_values(array_filter(
        $selfCheck->errors(),
        static fn (IdentityFinding $f): bool => $f->code === 'CODEOWNERS_PLACEHOLDER_ACTIVE',
    ));

    if ($unresolved !== []) {
        echo "  REFUSED  the rendered file still names an unresolved owner\n";

        foreach ($unresolved as $finding) {
            printf("           %s\n", $finding->summary);
        }

        echo "           Nothing was written.\n";

        return 1;
    }

    file_put_contents($absoluteTarget, $body);
    printf("  WROTE    %s\n", $absoluteTarget);
    echo "           Nothing was applied. Diff it against .github/CODEOWNERS and commit\n";
    echo "           deliberately, then re-run verify_repository_governance.php.\n";

    return 0;
}
