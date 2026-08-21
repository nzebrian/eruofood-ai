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
 *   php scripts/verify_repository_governance.php --rulesets=rulesets.json
 *
 * With that, the external checks are evaluated for real rather than deferred.
 *
 * Exit 0 when every repository-side check passes. A zero exit says nothing
 * about whether GitHub is enforcing anything.
 */

// scripts -> api -> apps -> <repo root>. Three levels, not two: apps/api is
// the Laravel root, but governance artifacts live across the whole repository.
$repoRoot = dirname(__DIR__, 3);

$rulesetsFile = null;
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--rulesets=')) {
        $rulesetsFile = substr((string) $arg, strlen('--rulesets='));
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
    foreach (['main-ruleset.json', 'production-tags-ruleset.json', 'required-checks.json'] as $file) {
        readJson($governanceDir.'/'.$file);
    }

    return [true, '3 files'];
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

// -- 7. What only GitHub can answer -------------------------------------------

section('7) GitHub-side — not provable from this repository');

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

externalCheck('reviewer identities are configured (a second account with write access)');
externalCheck('CODEOWNER identities resolve (GET /codeowners/errors returns zero)');
externalCheck('release actor identities are configured for v*.*.* tag creation');

// -- Result -------------------------------------------------------------------

echo "\n", str_repeat('=', 72), "\n";
printf("RESULT: %d passed, %d failed, %d external/admin required\n", $passed, $failed, $external);

if ($external > 0) {
    echo "\nEXTERNAL items are NOT failures and NOT passes. They are the parts of\n";
    echo "governance that this repository cannot prove anything about. See\n";
    echo ".github/governance/APPLY_GOVERNANCE.md.\n";
}

exit($failed === 0 ? 0 : 1);
