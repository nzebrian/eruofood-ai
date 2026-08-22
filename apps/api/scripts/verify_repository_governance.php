<?php

declare(strict_types=1);

/**
 * M29-A — is repository governance actually configured, or does it just look it?
 *
 * ## The distinction this script exists to hold
 *
 * Before M29-A, `.github/CODEOWNERS` had named six teams for months. The file
 * was present, well-formatted and plausible, and every single owner in it was
 * unresolvable — `GET /codeowners/errors` returned eight errors. Nothing in the
 * repository could have told you that, because the repository was not the
 * authority. GitHub was.
 *
 * So this validator reports in two categories and never blurs them:
 *
 *   PASS                     — proved from files in this repository.
 *   EXTERNAL / ADMIN REQUIRED — depends on GitHub state or on identities nobody
 *                               has supplied. Deferred, never assumed.
 *
 * **A JSON artifact describing a ruleset is not evidence that the ruleset
 * exists.** The artifacts under `.github/governance/` are prepared, not applied;
 * an EXTERNAL item is never upgraded to PASS because a file describing it is on
 * disk. That inversion is exactly the mistake the CODEOWNERS file made.
 *
 * ## Feeding it real data
 *
 *   gh api /repos/{owner}/{repo}/rulesets > rulesets.json
 *   gh api /repos/{owner}/{repo}/codeowners/errors > codeowners-errors.json
 *   php scripts/verify_repository_governance.php \
 *       --rulesets=rulesets.json --codeowners-errors=codeowners-errors.json
 *
 * With those, the matching external checks are evaluated for real rather than
 * deferred. Everything still unanswered stays EXTERNAL — supplying one file
 * does not resolve the others.
 *
 * Exit 0 when every repository-side check passes. A zero exit says nothing
 * about whether GitHub is enforcing anything.
 *
 * M29-B added section 7: the identity configuration that fills in the
 * `<OWNER:...>` tokens, and the activation readiness that follows from it. The
 * two-category rule is unchanged there — a resolved identity file is still not
 * evidence that the account exists or can push.
 */

require __DIR__.'/../vendor/autoload.php';

use EruoFood\Shared\Domain\Governance\ActivationState;
use EruoFood\Shared\Domain\Governance\GovernanceRole;
use EruoFood\Shared\Domain\Governance\IdentityFinding;
use EruoFood\Shared\Domain\Governance\IdentityPolicy;
use EruoFood\Shared\Domain\Governance\OwnershipDeclaration;

// scripts -> api -> apps -> <repo root>. Three levels, not two: apps/api is
// the Laravel root, but governance artifacts live across the whole repository.
$repoRoot = dirname(__DIR__, 3);

// Live GitHub data, supplied as files rather than fetched. Read-only by
// construction: this script has no way to reach GitHub, so it has no way to
// change anything there. Whoever runs `gh api` decides what it sees.
$rulesetsFile = null;
$codeownerErrorsFile = null;

foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--rulesets=')) {
        $rulesetsFile = substr((string) $arg, strlen('--rulesets='));
    }
    if (str_starts_with((string) $arg, '--codeowners-errors=')) {
        $codeownerErrorsFile = substr((string) $arg, strlen('--codeowners-errors='));
    }
}

$passed = 0;
$failed = 0;
$external = 0;

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** @param callable():array{bool, string} $check */
function verify(string $description, callable $check): void
{
    global $passed, $failed;

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    $ok ? $passed++ : $failed++;
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $description, $detail === '' ? '' : "  ({$detail})");
}

/**
 * Something only GitHub can answer.
 *
 * @param null|callable():array{bool, string} $check evaluated only when live data was supplied
 */
function externalCheck(string $description, ?callable $check = null): void
{
    global $external, $passed, $failed;

    if ($check === null) {
        $external++;
        printf("  EXTERNAL / ADMIN REQUIRED  %s\n", $description);

        return;
    }

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    $ok ? $passed++ : $failed++;
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $description, $detail === '' ? '' : "  ({$detail})");
}

function readJson(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("missing file: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("not a JSON object: {$path}");
    }

    return $decoded;
}

/** Find a rule of a given type inside a prepared ruleset artifact. */
function ruleOfType(array $ruleset, string $type): ?array
{
    foreach ($ruleset['rules'] ?? [] as $rule) {
        if (($rule['type'] ?? null) === $type) {
            return $rule;
        }
    }

    return null;
}

echo "EruoFood — repository governance verification\n";
echo str_repeat('=', 72), "\n";
echo "Repository root: {$repoRoot}\n";
echo $rulesetsFile === null
    ? "Live ruleset data: NOT SUPPLIED (GitHub-side checks will be deferred)\n"
    : "Live ruleset data: {$rulesetsFile}\n";

$governanceDir = $repoRoot.'/.github/governance';

// -- 1. Artifacts -------------------------------------------------------------

section('1) Governance artifacts are present and parse');

$requiredArtifacts = [
    'main-ruleset.json',
    'production-tags-ruleset.json',
    'required-checks.json',
    'identities.example.json',
    'ownership.json',
    'main-ruleset.sole-owner.json',
    'README.md',
    'APPLY_GOVERNANCE.md',
    'VERIFY_GOVERNANCE.md',
    'BREAK_GLASS.md',
];

verify('every governance artifact exists', function () use ($governanceDir, $requiredArtifacts): array {
    $missing = array_values(array_filter(
        $requiredArtifacts,
        static fn (string $f): bool => ! is_file($governanceDir.'/'.$f),
    ));

    return [$missing === [], $missing === [] ? count($requiredArtifacts).' artifacts' : 'missing='.implode(',', $missing)];
});

verify('every JSON artifact parses', function () use ($governanceDir): array {
    $files = ['main-ruleset.json', 'main-ruleset.sole-owner.json', 'production-tags-ruleset.json', 'required-checks.json', 'identities.example.json', 'ownership.json'];

    foreach ($files as $file) {
        readJson($governanceDir.'/'.$file);
    }

    return [true, count($files).' files'];
});

// -- 2. main ruleset ----------------------------------------------------------

section('2) The prepared main ruleset encodes the intended policy');

$mainRuleset = null;

try {
    $mainDoc = readJson($governanceDir.'/main-ruleset.json');
    $mainRuleset = $mainDoc['rulesets'][0] ?? null;
} catch (Throwable) {
    $mainRuleset = null;
}

verify('it targets refs/heads/main as an active branch ruleset', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $include = $mainRuleset['conditions']['ref_name']['include'] ?? [];

    return [
        ($mainRuleset['target'] ?? null) === 'branch'
            && ($mainRuleset['enforcement'] ?? null) === 'active'
            && in_array('refs/heads/main', $include, true),
        'target='.($mainRuleset['target'] ?? '?').' enforcement='.($mainRuleset['enforcement'] ?? '?'),
    ];
});

foreach (['deletion' => 'main cannot be deleted', 'non_fast_forward' => 'main cannot be force-pushed'] as $type => $label) {
    verify($label, function () use ($mainRuleset, $type): array {
        return [$mainRuleset !== null && ruleOfType($mainRuleset, $type) !== null, "rule={$type}"];
    });
}

verify('a pull request is required, with the four review protections', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'pull_request');
    if ($rule === null) {
        return [false, 'no pull_request rule'];
    }

    $p = $rule['parameters'] ?? [];
    $problems = [];

    // The approval count is allowed to be 0 *if* documented — a single-maintainer
    // repository cannot satisfy 1, because GitHub forbids self-approval. What is
    // never acceptable is the rest of the protections being switched off.
    if (! is_int($p['required_approving_review_count'] ?? null)) {
        $problems[] = 'required_approving_review_count not set';
    }
    foreach (['dismiss_stale_reviews_on_push', 'require_code_owner_review', 'require_last_push_approval'] as $flag) {
        if (($p[$flag] ?? false) !== true) {
            $problems[] = $flag.' not true';
        }
    }

    return [
        $problems === [],
        $problems === []
            ? 'approvals='.$p['required_approving_review_count'].', stale dismissal, code-owner review, last-push approval'
            : implode('; ', $problems),
    ];
});

verify('status checks are required, strictly', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'required_status_checks');
    if ($rule === null) {
        return [false, 'no required_status_checks rule'];
    }

    $p = $rule['parameters'] ?? [];
    $contexts = array_column($p['required_status_checks'] ?? [], 'context');

    return [
        ($p['strict_required_status_checks_policy'] ?? false) === true && $contexts !== [],
        count($contexts).' contexts, strict='.var_export($p['strict_required_status_checks_policy'] ?? null, true),
    ];
});

verify('no standing bypass actor', function () use ($mainRuleset): array {
    // The single most important line in the artifact. A bypass actor is
    // invisible in day-to-day use and removes every rule at once.
    return [
        $mainRuleset !== null && ($mainRuleset['bypass_actors'] ?? null) === [],
        'bypass_actors='.json_encode($mainRuleset['bypass_actors'] ?? null),
    ];
});

verify('linear history is NOT required, preserving the merge-commit workflow', function () use ($mainRuleset): array {
    // docs/ROLLBACK_PLAN.md section 7 reverts the M27 merge with `-m 1`.
    // Requiring linear history would forbid the workflow the project runs on.
    return [$mainRuleset !== null && ruleOfType($mainRuleset, 'required_linear_history') === null, ''];
});

verify('commit signatures are NOT yet required', function () use ($mainRuleset): array {
    // Signing is not configured. Requiring it now would block every commit,
    // including the one that would configure it.
    return [$mainRuleset !== null && ruleOfType($mainRuleset, 'required_signatures') === null, ''];
});

// -- 3. Tag rulesets ----------------------------------------------------------

section('3) The prepared tag rulesets protect the production release path');

$tagRulesets = [];
$tagDoc = [];

try {
    $tagDoc = readJson($governanceDir.'/production-tags-ruleset.json');
    $tagRulesets = $tagDoc['rulesets'] ?? [];
} catch (Throwable) {
    $tagRulesets = [];
}

verify('two tag rulesets are prepared', function () use ($tagRulesets): array {
    // Two, because GitHub scopes bypass_actors to a whole ruleset: the actors
    // allowed to create a release tag must not thereby be allowed to delete one.
    return [count($tagRulesets) === 2, 'count='.count($tagRulesets)];
});

verify('both target refs/tags/v* and are active', function () use ($tagRulesets): array {
    foreach ($tagRulesets as $rs) {
        $include = $rs['conditions']['ref_name']['include'] ?? [];
        if (($rs['target'] ?? null) !== 'tag' || ($rs['enforcement'] ?? null) !== 'active'
            || ! in_array('refs/tags/v*', $include, true)) {
            return [false, 'offending ruleset: '.($rs['name'] ?? '?')];
        }
    }

    return [$tagRulesets !== [], count($tagRulesets).' rulesets'];
});

verify('tag creation is restricted', function () use ($tagRulesets): array {
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') !== null) {
            return [true, 'in "'.($rs['name'] ?? '?').'"'];
        }
    }

    return [false, 'no ruleset carries a creation rule'];
});

verify('tags cannot be deleted or moved', function () use ($tagRulesets): array {
    $found = [];
    foreach ($tagRulesets as $rs) {
        foreach (['deletion', 'non_fast_forward', 'update'] as $type) {
            if (ruleOfType($rs, $type) !== null) {
                $found[$type] = true;
            }
        }
    }

    $missing = array_values(array_diff(['deletion', 'non_fast_forward', 'update'], array_keys($found)));

    return [$missing === [], $missing === [] ? 'deletion, non_fast_forward, update' : 'missing='.implode(',', $missing)];
});

verify('the immutability ruleset has no bypass actor', function () use ($tagRulesets): array {
    // Creation may be bypassed by named release actors. Immutability may not be
    // bypassed by anybody — a release tag its creator can delete is not a record.
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'deletion') !== null && ($rs['bypass_actors'] ?? null) !== []) {
            return [false, '"'.($rs['name'] ?? '?').'" carries bypass actors'];
        }
    }

    return [$tagRulesets !== [], ''];
});

verify('creation authority and immutability are not in the same ruleset', function () use ($tagRulesets): array {
    // M29-B. The failure this forbids does not look like a failure: one ruleset
    // that both restricts creation and forbids deletion has to carry the release
    // actors as bypass_actors to be usable at all — and bypass_actors is scoped
    // to the whole ruleset, so those actors become exempt from deletion and
    // update too. The configuration then reads "release tags are protected" and
    // means "the release actor may delete any release tag".
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') === null) {
            continue;
        }

        foreach (['deletion', 'non_fast_forward', 'update'] as $type) {
            if (ruleOfType($rs, $type) !== null) {
                return [false, '"'.($rs['name'] ?? '?').'" restricts creation and enforces '.$type];
            }
        }
    }

    return [$tagRulesets !== [], 'the two-ruleset split holds'];
});

verify('the creation ruleset carries no bypass actor yet', function () use ($tagRulesets): array {
    // Not a permanent invariant — this is where release actors legitimately go.
    // It is checked so that the day one appears, it appears because somebody
    // decided to put it there, having read production-tags-ruleset.json's
    // actor_placeholder_contract.
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') !== null && ($rs['bypass_actors'] ?? null) !== []) {
            return [false, '"'.($rs['name'] ?? '?').'" already names release actors — confirm each is intended'];
        }
    }

    return [$tagRulesets !== [], 'no release actor configured yet (tag creation denied to everyone)'];
});

// -- 4. Required checks -------------------------------------------------------

section('4) Required checks exist as jobs, and will report on every pull request');

$requiredChecks = [];

try {
    $checksDoc = readJson($governanceDir.'/required-checks.json');
    $requiredChecks = $checksDoc['required'] ?? [];
} catch (Throwable) {
    $requiredChecks = [];
}

verify('the required list is populated', function () use ($requiredChecks): array {
    return [count($requiredChecks) >= 5, 'count='.count($requiredChecks)];
});

verify('the main ruleset requires exactly the documented contexts', function () use ($mainRuleset, $requiredChecks): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'required_status_checks');
    $inRuleset = array_column($rule['parameters']['required_status_checks'] ?? [], 'context');
    $documented = array_column($requiredChecks, 'context');

    sort($inRuleset);
    sort($documented);

    return [$inRuleset === $documented && $inRuleset !== [], count($inRuleset).' contexts agree'];
});

verify('every required context exists as a job name in the workflow it names', function () use ($requiredChecks, $repoRoot): array {
    $problems = [];

    foreach ($requiredChecks as $check) {
        $context = (string) ($check['context'] ?? '');
        $workflow = $repoRoot.'/'.($check['workflow'] ?? '');

        if (! is_file($workflow)) {
            $problems[] = "{$context}: workflow missing";

            continue;
        }

        // Job names are `    name: <context>`. Compared literally, because a
        // context differing by one character (the U+00B7 middle dot is the
        // usual culprit) never reports, and a required check that never
        // reports blocks every pull request forever.
        $body = (string) file_get_contents($workflow);
        if (! preg_match('/^\s*name:\s*'.preg_quote($context, '/').'\s*$/m', $body)) {
            $problems[] = "{$context}: no matching job name";
        }
    }

    if ($requiredChecks === []) {
        return [false, 'no required checks declared — nothing was verified'];
    }

    return [$problems === [], $problems === [] ? count($requiredChecks).' contexts matched' : implode('; ', $problems)];
});

verify('no required workflow filters its pull_request trigger by path', function () use ($requiredChecks, $repoRoot): array {
    // The trap this whole milestone turns on. GitHub treats a required check
    // that never reports as pending, not satisfied — so a path filter on
    // `pull_request` silently converts a required check into a permanent block
    // on every unrelated pull request.
    $problems = [];

    foreach (array_unique(array_column($requiredChecks, 'workflow')) as $relative) {
        $path = $repoRoot.'/'.$relative;
        if (! is_file($path)) {
            $problems[] = "{$relative}: missing";

            continue;
        }

        $body = (string) file_get_contents($path);

        // Isolate the `pull_request:` block: everything indented beneath it,
        // ignoring comments.
        if (preg_match('/^  pull_request:\s*$\n((?:^(?:    .*|\s*)$\n)*)/m', $body, $m)) {
            $block = preg_replace('/^\s*#.*$/m', '', $m[1]) ?? '';
            if (preg_match('/^\s+paths(-ignore)?:/m', $block)) {
                $problems[] = "{$relative}: pull_request is path-filtered";
            }
        } elseif (! preg_match('/^  pull_request:/m', $body)) {
            $problems[] = "{$relative}: no pull_request trigger";
        }
    }

    $workflows = array_unique(array_column($requiredChecks, 'workflow'));
    if ($workflows === []) {
        return [false, 'no workflows to check — nothing was verified'];
    }

    return [
        $problems === [],
        $problems === [] ? count($workflows).' workflow(s) trigger unconditionally' : implode('; ', $problems),
    ];
});

verify('the known-failing and tag-only workflows are not required', function () use ($requiredChecks): array {
    // GA Docker Certification fails on main today; release.yml is tag-triggered
    // and cannot report on a pull request. Requiring either blocks everything.
    $contexts = array_column($requiredChecks, 'context');
    $workflows = array_column($requiredChecks, 'workflow');

    if ($contexts === []) {
        return [false, 'no required checks declared — nothing was verified'];
    }

    $offenders = array_values(array_filter(
        array_merge($contexts, $workflows),
        static fn (string $v): bool => str_contains($v, 'GA Docker') || str_contains($v, 'release.yml')
            || str_contains($v, 'ga-docker-certification'),
    ));

    return [$offenders === [], $offenders === [] ? count($contexts).' contexts, none excluded-by-policy' : 'wrongly required: '.implode(',', $offenders)];
});

// -- 5. CODEOWNERS ------------------------------------------------------------

section('5) CODEOWNERS claims no owner it cannot resolve');

$codeownersPath = $repoRoot.'/.github/CODEOWNERS';

/** @return list<array{int, string, list<string>}> line number, pattern, owners */
$activeCodeownerRules = static function () use ($codeownersPath): array {
    if (! is_file($codeownersPath)) {
        return [];
    }

    $rules = [];
    foreach (file($codeownersPath, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $pattern = array_shift($parts);
        $rules[] = [$i + 1, (string) $pattern, array_values($parts)];
    }

    return $rules;
};

verify('the file exists', function () use ($codeownersPath): array {
    return [is_file($codeownersPath), $codeownersPath];
});

verify('no active rule names an owner that cannot resolve', function () use ($activeCodeownerRules, $codeownersPath): array {
    // An owner handle must be @user, @org/team, or an email. Anything else —
    // including a placeholder token — is not a real owner, and a CODEOWNERS
    // file that names unresolvable owners is the defect M29-A found: it reads
    // as configured while enforcing nothing.
    $problems = [];

    foreach ($activeCodeownerRules() as [$line, $pattern, $owners]) {
        if ($owners === []) {
            $problems[] = "line {$line}: '{$pattern}' has no owner";

            continue;
        }

        foreach ($owners as $owner) {
            $looksReal = preg_match('/^@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\/[A-Za-z0-9._-]+)?$/', $owner) === 1
                || filter_var($owner, FILTER_VALIDATE_EMAIL) !== false;

            if (! $looksReal) {
                $problems[] = "line {$line}: '{$owner}' is not a resolvable owner handle";
            }
        }
    }

    if (! is_file($codeownersPath)) {
        return [false, 'CODEOWNERS missing — nothing was verified'];
    }

    $count = count($activeCodeownerRules());

    return [
        $problems === [],
        $problems === []
            ? ($count === 0 ? 'no rule is active (expected while owners are unresolved)' : $count.' active rule(s), all well-formed')
            : implode('; ', $problems),
    ];
});

verify('unresolved ownership is marked, not faked', function () use ($codeownersPath, $activeCodeownerRules): array {
    // Either the domains are still placeholders (and every rule is commented
    // out), or real owners have been supplied (and no placeholder remains).
    // A file with both is half-migrated and will behave unpredictably.
    $body = is_file($codeownersPath) ? (string) file_get_contents($codeownersPath) : '';
    $hasPlaceholders = preg_match('/<OWNER:[A-Z_]+>/', $body) === 1;
    $activeRules = $activeCodeownerRules();

    if ($hasPlaceholders && $activeRules !== []) {
        return [false, 'placeholders and active rules coexist — finish the migration'];
    }

    return [true, $hasPlaceholders ? 'placeholders present, no rule active (expected pre-handover)' : 'no placeholders remain'];
});

// -- 6. Break-glass -----------------------------------------------------------

section('6) Break-glass is documented and auditable');

verify('every required incident field is documented', function () use ($governanceDir): array {
    $path = $governanceDir.'/BREAK_GLASS.md';
    if (! is_file($path)) {
        return [false, 'BREAK_GLASS.md missing'];
    }

    $body = strtoupper((string) file_get_contents($path));
    $fields = [
        'INCIDENT ID', 'REASON', 'RISK ASSESSMENT', 'AUTHORIZED BY',
        'TEMPORARY RULE CHANGE', 'START TIME', 'END TIME', 'ACTION PERFORMED',
        'VERIFICATION', 'RULE RESTORATION', 'POST-INCIDENT REVIEW',
    ];

    $missing = array_values(array_filter($fields, static fn (string $f): bool => ! str_contains($body, $f)));

    return [$missing === [], $missing === [] ? count($fields).' fields' : 'missing='.implode(', ', $missing)];
});

verify('it forbids a standing bypass', function () use ($governanceDir): array {
    $body = (string) @file_get_contents($governanceDir.'/BREAK_GLASS.md');

    return [
        str_contains($body, 'no standing bypass') || str_contains($body, 'No standing bypass')
            || str_contains($body, '**no standing bypass**'),
        '',
    ];
});

// Read before section 7 uses it: the mode decides how several later checks are
// judged, so it has to be established before any of them run.
$appliesTo = is_string($tagDoc['_meta']['applies_to'] ?? null) ? $tagDoc['_meta']['applies_to'] : '';
$ownershipDoc = null;

try {
    $ownershipDoc = readJson($governanceDir.'/ownership.json');
} catch (Throwable) {
    $ownershipDoc = null;
}

$ownership = OwnershipDeclaration::fromArray($ownershipDoc);
$identityPolicy = new IdentityPolicy(explode('/', $appliesTo)[0] ?? '', $ownership->mode);

// -- 7. Governance ownership mode (M29-I) -------------------------------------

section('7) Governance ownership mode');

verify('the ownership declaration is usable', function () use ($ownership): array {
    $errors = $ownership->errors();

    return [
        $errors === [],
        $errors === []
            ? 'mode='.$ownership->mode->value.' owner='.$ownership->repositoryOwner
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->code.': '.$f->summary, $errors)),
    ];
});

verify('the declared owner matches the repository the artifacts describe', function () use ($ownership, $appliesTo): array {
    // Two files name the repository. If they ever disagree, the owner-comparison
    // rules are being applied against the wrong account and every result that
    // depends on them is quietly meaningless.
    $expected = explode('/', $appliesTo)[0] ?? '';

    return [
        $expected !== '' && strcasecmp($expected, $ownership->repositoryOwner) === 0,
        "ownership.json={$ownership->repositoryOwner} production-tags-ruleset.json={$expected}",
    ];
});

verify('no participant is an AI assistant or bot', function () use ($ownership): array {
    // Claude and ChatGPT wrote much of this governance. Neither can hold
    // repository access, approve a pull request, or be accountable for a change
    // that moves money — and a synthetic reviewer would satisfy every other
    // check in this file while providing none of the review it simulates.
    $offenders = array_values(array_filter(
        $ownership->humanParticipants,
        static fn (string $h): bool => OwnershipDeclaration::isNonHuman($h),
    ));

    return [$offenders === [], $offenders === [] ? count($ownership->humanParticipants).' human participant(s)' : 'non-human: '.implode(', ', $offenders)];
});

verify('the ruleset for the declared mode matches that mode', function () use ($ownership, $governanceDir): array {
    // The whole point of declaring a mode is that the ruleset actually applied
    // agrees with it. SOLE_OWNER with one required approval blocks every merge;
    // MULTI_PERSON with zero silently discards the review it claims to require.
    $doc = readJson($governanceDir.'/'.$ownership->mode->mainRulesetArtifact());
    $rule = ruleOfType($doc['rulesets'][0] ?? [], 'pull_request');
    $p = $rule['parameters'] ?? [];

    $problems = [];
    $expectedCount = $ownership->mode->requiredApprovingReviewCount();

    if (($p['required_approving_review_count'] ?? null) !== $expectedCount) {
        $problems[] = sprintf('required_approving_review_count=%s, expected %d', json_encode($p['required_approving_review_count'] ?? null), $expectedCount);
    }

    if (($p['require_code_owner_review'] ?? null) !== $ownership->mode->supportsCodeOwnerReview()) {
        $problems[] = 'require_code_owner_review='.json_encode($p['require_code_owner_review'] ?? null);
    }

    return [
        $problems === [],
        $problems === []
            ? $ownership->mode->mainRulesetArtifact().': approvals='.$expectedCount.', code-owner review='.var_export($ownership->mode->supportsCodeOwnerReview(), true)
            : implode('; ', $problems),
    ];
});

verify('the sole-owner ruleset relaxes only the two human-review parameters', function () use ($governanceDir): array {
    // Everything except the review parameters must be byte-identical to the
    // multi-person policy. A "mode" that quietly dropped a status check or
    // opened a bypass would be far worse than no mode at all.
    $multi = readJson($governanceDir.'/main-ruleset.json')['rulesets'][0] ?? [];
    $sole = readJson($governanceDir.'/main-ruleset.sole-owner.json')['rulesets'][0] ?? [];

    $problems = [];

    if (($sole['bypass_actors'] ?? null) !== []) {
        $problems[] = 'sole-owner ruleset carries bypass actors';
    }

    if (array_column($multi['rules'] ?? [], 'type') !== array_column($sole['rules'] ?? [], 'type')) {
        $problems[] = 'rule types differ';
    }

    if (($multi['conditions'] ?? null) !== ($sole['conditions'] ?? null)) {
        $problems[] = 'conditions differ';
    }

    if (ruleOfType($multi, 'required_status_checks') !== ruleOfType($sole, 'required_status_checks')) {
        $problems[] = 'required status checks differ — the automated gates must be identical in both modes';
    }

    $mp = ruleOfType($multi, 'pull_request')['parameters'] ?? [];
    $sp = ruleOfType($sole, 'pull_request')['parameters'] ?? [];

    foreach (array_keys($mp) as $key) {
        $relaxable = in_array($key, ['required_approving_review_count', 'require_code_owner_review', 'require_last_push_approval'], true);

        if (! $relaxable && ($mp[$key] ?? null) !== ($sp[$key] ?? null)) {
            $problems[] = "pull_request.{$key} differs and is not a review parameter";
        }
    }

    return [$problems === [], $problems === [] ? 'only the review parameters differ' : implode('; ', $problems)];
});

foreach ($ownership->mode->summaryLines() as $line) {
    printf("  %s\n", $line);
}

if (! $ownership->mode->supportsIndependentReview()) {
    // Printed rather than passed. A reader skimming a green run must not be
    // able to come away thinking a second person reviewed anything.
    externalCheck('independent human review (DEFERRED under SOLE_OWNER — requires a second real human)');
    externalCheck('CODEOWNERS enforcement (DEFERRED under SOLE_OWNER — CODEOWNERS is inert)');
    externalCheck('finance four-eyes review (DEFERRED under SOLE_OWNER — one human participant)');
}

// -- 8. Identity configuration and activation readiness (M29-B) ---------------

section('8) Identity configuration and activation readiness');

$identitiesPath = $governanceDir.'/identities.json';
$examplePath = $governanceDir.'/identities.example.json';


$activeIdentities = null;
$identitiesReadable = true;

if (is_file($identitiesPath)) {
    try {
        $activeIdentities = readJson($identitiesPath);
    } catch (Throwable) {
        $identitiesReadable = false;
    }
}

$assessment = $identityPolicy->evaluate(
    $activeIdentities,
    is_file($codeownersPath) ? (string) file_get_contents($codeownersPath) : '',
    array_values(array_filter($tagRulesets, 'is_array')),
);

verify('the identity example is present and parses', function () use ($examplePath): array {
    $doc = readJson($examplePath);

    return [$doc !== [], basename($examplePath)];
});

verify('the example cannot be mistaken for an active configuration', function () use ($examplePath): array {
    // Three independent signals, because this is the mistake with the longest
    // feedback loop: nothing breaks until a real pull request needs a real
    // reviewer, and by then nobody is looking at this file.
    $doc = readJson($examplePath);
    $problems = [];

    if (($doc['_example'] ?? null) !== true) {
        $problems[] = 'no "_example": true marker';
    }

    $body = (string) file_get_contents($examplePath);
    if (! str_contains($body, '<EXAMPLE:')) {
        $problems[] = 'no <EXAMPLE:...> placeholder values';
    }

    if (! str_contains(basename($examplePath), '.example.')) {
        $problems[] = 'filename does not say example';
    }

    return [$problems === [], $problems === [] ? 'marker, placeholders and filename' : implode('; ', $problems)];
});

verify('the example names nobody', function () use ($examplePath, $identityPolicy): array {
    // Run the real policy over the example and require it to be rejected. If the
    // shipped template ever became activatable, somebody would activate it — and
    // a CODEOWNERS full of plausible handles that resolve to nothing is the
    // M29-A defect with better spelling.
    $doc = readJson($examplePath);
    $result = $identityPolicy->evaluate($doc, '', []);

    $codes = array_map(static fn (IdentityFinding $f): string => $f->code, $result->errors());

    return [
        in_array('IDENTITY_EXAMPLE_USED_AS_ACTIVE', $codes, true) && ! $result->isReadyForActivation(),
        'rejected as: '.(implode(', ', $codes) ?: 'nothing — the example is activatable, which it must not be'),
    ];
});

verify('the identity policy agrees CODEOWNERS claims no unresolvable owner', function () use ($assessment): array {
    // A cross-check, not a repeat of section 5. That check uses a deliberately
    // loose handle pattern as a backstop; this one uses the strict rules that
    // gate substitution. Agreement between them is the property worth having —
    // if they ever diverge, a handle could pass the gate and fail the backstop,
    // or worse, the other way round.
    $offenders = array_values(array_filter(
        $assessment->errors(),
        static fn (IdentityFinding $f): bool => $f->code === 'CODEOWNERS_PLACEHOLDER_ACTIVE',
    ));

    return [
        $offenders === [],
        $offenders === []
            ? 'both implementations agree'
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->summary, $offenders)),
    ];
});

verify('the active identity configuration, if any, has no errors', function () use ($assessment, $identitiesReadable, $identitiesPath): array {
    if (! $identitiesReadable) {
        return [false, basename($identitiesPath).' does not parse'];
    }

    $errors = $assessment->errors();

    return [
        $errors === [],
        $errors === []
            ? $assessment->state->value
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->code.': '.$f->summary, $errors)),
    ];
});

printf(
    "  ACTIVATION STATE  %s — %s\n",
    strtoupper(str_replace('_', ' ', $assessment->state->value)),
    $assessment->state->summary(),
);

foreach (GovernanceRole::cases() as $role) {
    $handles = $assessment->resolved[$role->value] ?? null;
    printf("    %-14s %s\n", $role->value, $handles === null ? 'unresolved' : implode(' ', $handles));
}

foreach ($assessment->warnings() as $warning) {
    printf("  WARNING %s  %s\n", $warning->code, $warning->summary);
}

// Even a flawless identity file leaves these open, and there is no code path
// that removes one. See IdentityAssessment::externalRequirements(). They are
// deferred unless somebody supplies GitHub's own answer; a file in this
// repository is never that answer.
$liveCodeownerErrors = null;

if ($codeownerErrorsFile !== null) {
    try {
        $decoded = json_decode((string) file_get_contents($codeownerErrorsFile), true, 512, JSON_THROW_ON_ERROR);
        $liveCodeownerErrors = is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        printf("  FAIL could not read --codeowners-errors file  (%s)\n", $e->getMessage());
        $failed++;
    }
}

foreach ($assessment->externalRequirements() as $requirement) {
    if (! str_contains($requirement, 'codeowners/errors') || $liveCodeownerErrors === null) {
        externalCheck($requirement);

        continue;
    }

    externalCheck($requirement, function () use ($liveCodeownerErrors, $activeCodeownerRules): array {
        $errors = is_array($liveCodeownerErrors['errors'] ?? null) ? $liveCodeownerErrors['errors'] : [];
        $activeRules = count($activeCodeownerRules());

        // Zero errors is necessary and not sufficient, and the insufficiency is
        // the whole M29-A story: a fully commented-out file also reports zero.
        // Reporting that as a pass would mean this validator confirming review
        // routing works on a file that routes nothing.
        if ($errors !== []) {
            return [false, count($errors).' unknown owner(s): '.json_encode(array_slice($errors, 0, 3))];
        }

        if ($activeRules === 0) {
            return [false, 'zero errors, but no rule is active — a commented-out file also reports zero'];
        }

        return [true, "zero errors across {$activeRules} active rule(s)"];
    });
}

if ($assessment->state === ActivationState::ReadyForActivation) {
    echo "\n  READY FOR ACTIVATION is a statement about this repository, not about\n";
    echo "  GitHub. Nothing above upgrades an EXTERNAL item to a PASS.\n";
}

// -- 9. What only GitHub can answer -------------------------------------------

section('9) GitHub-side — not provable from this repository');

$liveRulesets = null;

if ($rulesetsFile !== null) {
    try {
        $decoded = json_decode((string) file_get_contents($rulesetsFile), true, 512, JSON_THROW_ON_ERROR);
        $liveRulesets = is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        printf("  FAIL could not read --rulesets file  (%s)\n", $e->getMessage());
        $failed++;
    }
}

if ($liveRulesets === null) {
    externalCheck('the main ruleset is actually active on GitHub');
    externalCheck('the production tag rulesets are actually active on GitHub');
    externalCheck('required status checks are enforced by GitHub, not advisory');
    externalCheck('no bypass actors are configured on the live rulesets');
    externalCheck('branch protection is effective (direct push and force-push refused)');
} else {
    $byName = [];
    foreach ($liveRulesets as $rs) {
        if (is_array($rs) && isset($rs['name'])) {
            $byName[(string) $rs['name']] = $rs;
        }
    }

    externalCheck('the main ruleset is actually active on GitHub', function () use ($byName): array {
        foreach ($byName as $name => $rs) {
            if (str_contains(strtolower($name), 'main') && ($rs['enforcement'] ?? null) === 'active') {
                return [true, "\"{$name}\""];
            }
        }

        return [false, 'no active ruleset targeting main; found: '.(implode(', ', array_keys($byName)) ?: 'none')];
    });

    externalCheck('the production tag rulesets are actually active on GitHub', function () use ($byName): array {
        $tagRules = array_filter(
            $byName,
            static fn (array $rs): bool => ($rs['target'] ?? null) === 'tag' && ($rs['enforcement'] ?? null) === 'active',
        );

        return [count($tagRules) >= 2, 'active tag rulesets='.count($tagRules)];
    });

    externalCheck('no bypass actors on the main or tag-immutability rulesets', function () use ($byName): array {
        $offenders = [];
        foreach ($byName as $name => $rs) {
            $isCreationOnly = str_contains(strtolower($name), 'creation');
            if (! $isCreationOnly && ($rs['bypass_actors'] ?? []) !== []) {
                $offenders[] = $name;
            }
        }

        return [$offenders === [], $offenders === [] ? '' : 'bypass actors on: '.implode(', ', $offenders)];
    });

    // Even with live ruleset data these remain unprovable here: the first needs
    // the per-branch rules endpoint, the second needs somebody to try a push.
    externalCheck('required status checks are enforced by GitHub, not advisory');
    externalCheck('branch protection is effective (direct push and force-push refused)');
}

// The identity-side externals are reported in section 7, from the assessment
// itself, so they are not repeated here.

// -- Result -------------------------------------------------------------------

echo "\n", str_repeat('=', 72), "\n";
printf("RESULT: %d passed, %d failed, %d external/admin required\n", $passed, $failed, $external);

if ($external > 0) {
    echo "\nEXTERNAL items are NOT failures and NOT passes. They are the parts of\n";
    echo "governance that this repository cannot prove anything about. See\n";
    echo ".github/governance/APPLY_GOVERNANCE.md.\n";
}

exit($failed === 0 ? 0 : 1);
