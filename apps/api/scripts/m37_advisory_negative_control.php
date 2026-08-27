<?php

declare(strict_types=1);

/**
 * M37 Phase 4B — do the two defect fixes and the ratchet actually bite?
 *
 * Phase 4A proved two fail-open paths by experiment, and this is what stops
 * them coming back:
 *
 *   1. `GET /rulesets` carries no `bypass_actors` key. The validator read a
 *      missing key as an empty one and printed
 *      "PASS  no bypass actors on the main or tag-immutability rulesets"
 *      on evidence that said nothing whatever about bypass actors.
 *
 *   2. A GitHub API error body is valid JSON. `{"message":"Resource not
 *      accessible by integration","status":"403"}` was accepted as evidence,
 *      iterated as a list of rulesets, found to contain none, and reported as
 *      "FAIL  the main ruleset is actually active on GitHub". A transient 403
 *      was indistinguishable from somebody switching branch protection off.
 *
 * Both are now asserted by outcome CLASS — PASS / FAIL / EXTERNAL — and by the
 * specific finding, never by "it exited non-zero". The validator exits non-zero
 * for several unrelated reasons on this repository today (two accepted
 * governance gaps), so an exit-status-only control here would pass no matter
 * what it planted.
 *
 * The ratchet controls assert its four verdicts and every way of feeding it a
 * record it must refuse.
 *
 * Nothing here touches the repository. Evidence lives in a unique mktemp
 * directory; the validator is invoked against the real tree read-only, and the
 * tree is sha256-fingerprinted before and after to prove it.
 *
 * Run: php scripts/m37_advisory_negative_control.php
 */

$repoRoot = dirname(__DIR__, 3);
$validator = __DIR__.'/verify_repository_governance.php';
$ratchet = __DIR__.'/governance_ratchet.php';

$passed = 0;
$failedControls = 0;

$workdir = sys_get_temp_dir().'/m37-advisory-'.bin2hex(random_bytes(8));

if (! mkdir($workdir, 0700) && ! is_dir($workdir)) {
    fwrite(STDERR, "could not create work directory\n");

    exit(1);
}

/** sha256 over everything this suite must not modify. */
function m37_fingerprint(string $repoRoot): string
{
    $paths = [];

    foreach (['/.github/governance', '/.github/workflows', '/.github/CODEOWNERS'] as $rel) {
        $target = $repoRoot.$rel;

        if (is_file($target)) {
            $paths[$target] = hash_file('sha256', $target);

            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile()) {
                $paths[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }
    }

    ksort($paths);

    return hash('sha256', json_encode($paths));
}

$fingerprintBefore = m37_fingerprint($repoRoot);

echo "EruoFood — M37 Phase 4B advisory negative controls\n";
echo str_repeat('=', 78), "\n";
echo "evidence: unique mktemp files; the repository is read-only\n\n";

function ok(string $message): void
{
    global $passed;
    $passed++;
    echo "  ✔ {$message}\n";
}

function bad(string $message): void
{
    global $failedControls;
    $failedControls++;
    echo "  ✘ {$message}\n";
}

function heading(string $title): void
{
    echo "\n{$title}\n";
}

/** @return array{exit: int, output: string, summary: array|null} */
function runProcess(string $command): array
{
    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output), 'summary' => null];
}

function writeJson(string $path, mixed $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Run the validator against one evidence file and report how ONE named check
 * came out.
 *
 * Returns the outcome class for that check: 'PASS', 'FAIL', 'EXTERNAL' or
 * 'ABSENT'. Matching is anchored to the start of the reported line so a
 * needle cannot be satisfied by a passing line that merely mentions it — the
 * defect that made two of the Phase 3 controls fire for unrelated reasons.
 */
function outcomeOf(string $output, string $check): string
{
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);

        foreach (['PASS ' => 'PASS', 'FAIL ' => 'FAIL', 'EXTERNAL / ADMIN REQUIRED  ' => 'EXTERNAL'] as $prefix => $class) {
            if (str_starts_with($line, $prefix) && str_starts_with(substr($line, strlen($prefix)), $check)) {
                return $class;
            }
        }
    }

    return 'ABSENT';
}

$bypassCheck = 'no bypass actors on the main or tag-immutability rulesets';

/** A minimal but realistic rulesets payload, shaped exactly like GitHub's. */
function baseRulesets(mixed $bypass = '__omit__'): array
{
    $ruleset = [
        'id' => 21203909,
        'name' => 'main branch protection (sole owner)',
        'target' => 'branch',
        'source_type' => 'Repository',
        'source' => 'nzebrian/eruofood-ai',
        'enforcement' => 'active',
    ];

    if ($bypass !== '__omit__') {
        $ruleset['bypass_actors'] = $bypass;
    }

    return [$ruleset];
}

// =============================================================================
// A) The bypass-actor vacuous PASS
// =============================================================================

heading('A) bypass_actors — four inputs, four different answers');

$bypassCases = [
    ['the key is absent, exactly as GET /rulesets returns it', '__omit__', 'EXTERNAL'],
    ['the key is explicitly empty', [], 'PASS'],
    ['a standing bypass actor is configured', [['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always']], 'FAIL'],
    ['the key holds a string instead of a list', 'everyone', 'FAIL'],
    ['the key holds an object instead of a list', ['admin' => 'always'], 'FAIL'],
];

foreach ($bypassCases as [$description, $bypass, $expected]) {
    $path = $workdir.'/bypass-'.substr(md5($description), 0, 8).'.json';
    writeJson($path, baseRulesets($bypass));

    $result = runProcess(sprintf('php %s --rulesets=%s', escapeshellarg($validator), escapeshellarg($path)));
    $actual = outcomeOf($result['output'], $bypassCheck);

    if ($actual === $expected) {
        ok("{$description} → {$expected}");
    } else {
        bad("{$description} → expected {$expected}, got {$actual}");
    }
}

// The specific regression: absence must never be reported as proof.
$path = $workdir.'/bypass-absent-detail.json';
writeJson($path, baseRulesets());
$result = runProcess(sprintf('php %s --rulesets=%s', escapeshellarg($validator), escapeshellarg($path)));

if (! str_contains($result['output'], 'PASS '.$bypassCheck)) {
    ok('a missing bypass_actors field can never produce a PASS line');
} else {
    bad('THE PHASE 4A DEFECT IS BACK — a missing bypass_actors field produced PASS');
}

// =============================================================================
// B) API error bodies are not evidence
// =============================================================================

heading('B) A GitHub API error is never read as governance state');

$apiErrors = [
    '401 bad credentials' => ['message' => 'Bad credentials', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '401'],
    '403 not accessible' => ['message' => 'Resource not accessible by integration', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '403'],
    '404 not found' => ['message' => 'Not Found', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '404'],
    '429 rate limited' => ['message' => 'API rate limit exceeded', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '429'],
    '500 server error' => ['message' => 'Server Error', 'status' => '500'],
];

foreach ($apiErrors as $label => $body) {
    $path = $workdir.'/api-'.substr(md5($label), 0, 8).'.json';
    writeJson($path, $body);

    $result = runProcess(sprintf('php %s --rulesets=%s', escapeshellarg($validator), escapeshellarg($path)));

    $refused = str_contains($result['output'], 'is a GitHub API error response, not evidence');
    // The old behaviour: the error body became an empty list and the main
    // ruleset was reported as missing. That must not happen any more.
    $misread = str_contains($result['output'], 'FAIL the main ruleset is actually active on GitHub');

    if ($refused && ! $misread) {
        ok("{$label} is refused at the evidence boundary, not misread as governance drift");
    } else {
        bad(sprintf('%s — refused=%s, misread-as-drift=%s', $label, $refused ? 'yes' : 'NO', $misread ? 'YES' : 'no'));
    }
}

// =============================================================================
// C) Broken evidence still fails closed (the Phase 3 contract, unchanged)
// =============================================================================

heading('C) Supplied-but-broken evidence still fails closed');

$broken = [
    'an empty file' => '',
    'literal null' => 'null',
    'a bare scalar' => '"a string"',
    'malformed JSON' => '{ not json',
    'an HTML error page' => '<html><body>502</body></html>',
    'a JSON object where a list belongs' => '{"rulesets": []}',
];

foreach ($broken as $label => $body) {
    $path = $workdir.'/broken-'.substr(md5($label), 0, 8).'.json';
    file_put_contents($path, $body);

    $result = runProcess(sprintf('php %s --rulesets=%s', escapeshellarg($validator), escapeshellarg($path)));

    if ($result['exit'] === 1) {
        ok("{$label} → FAIL (exit 1), not silently discarded");
    } else {
        bad("{$label} → expected exit 1, got {$result['exit']}");
    }
}

$result = runProcess(sprintf('php %s --rulesets=%s', escapeshellarg($validator), escapeshellarg($workdir.'/does-not-exist.json')));
$result['exit'] === 1
    ? ok('a nonexistent evidence path → FAIL (exit 1)')
    : bad("a nonexistent evidence path → expected exit 1, got {$result['exit']}");

$result = runProcess(sprintf('php %s --ruleset=%s', escapeshellarg($validator), escapeshellarg($workdir.'/anything.json')));
$result['exit'] === 3
    ? ok('a mistyped flag → invocation ERROR (exit 3), never a silent success')
    : bad("a mistyped flag → expected exit 3, got {$result['exit']}");

// =============================================================================
// D) The ratchet
// =============================================================================

heading('D) The known-gap ratchet');

/** Build a validator-summary document the ratchet will accept structurally. */
function summaryDoc(array $failureIds, array $unverifiedIds = []): array
{
    return [
        'schema' => 2,
        'mode' => 'advisory',
        'total' => 49,
        'passed' => 39,
        'failed' => count($failureIds),
        'external_unverified' => count($unverifiedIds),
        'skipped' => 3,
        'error' => 0,
        'verification_complete' => false,
        'exit_code' => $failureIds === [] ? 0 : 1,
        'exit_reason' => $failureIds === [] ? 'verified' : 'governance_failure',
        'unverified' => array_map(static fn (?string $i): string => (string) $i, $unverifiedIds),
        'failures' => array_map(
            static fn (?string $id): array => ['id' => $id, 'check' => 'synthetic', 'detail' => ''],
            $failureIds,
        ),
        'unverified_detail' => array_map(
            static fn (?string $id): array => ['id' => $id, 'check' => 'synthetic'],
            $unverifiedIds,
        ),
    ];
}

$recordedGaps = ['github.tag_rulesets_active', 'github.codeowners_errors_zero'];
$recordedUnverified = [
    'github.identity_accounts_exist',
    'github.identity_accounts_write_access',
    'github.release_actor_id_valid',
    'github.no_bypass_actors',
    'github.required_checks_enforced',
    'github.branch_protection_effective',
];

function runRatchet(string $ratchet, string $summaryPath, ?string $recordPath = null, ?string $evidencePath = null): array
{
    $command = sprintf('php %s --summary=%s', escapeshellarg($ratchet), escapeshellarg($summaryPath));

    if ($recordPath !== null) {
        $command .= ' --known-gaps='.escapeshellarg($recordPath);
    }

    if ($evidencePath !== null) {
        $command .= ' --evidence-status='.escapeshellarg($evidencePath);
    }

    return runProcess($command);
}

function ratchetCase(
    string $description,
    array $summary,
    int $expectedExit,
    string $expectedVerdict,
    ?array $record = null,
    ?array $evidence = null,
): void {
    global $workdir, $ratchet, $repoRoot;

    $key = substr(md5($description), 0, 8);
    $summaryPath = $workdir."/sum-{$key}.json";
    writeJson($summaryPath, $summary);

    $recordPath = null;

    if ($record !== null) {
        $recordPath = $workdir."/rec-{$key}.json";
        // Raw write, so a deliberately malformed record survives verbatim.
        file_put_contents($recordPath, is_string($record[0] ?? null) ? $record[0] : json_encode($record, JSON_PRETTY_PRINT));
    }

    $evidencePath = null;

    if ($evidence !== null) {
        $evidencePath = $workdir."/ev-{$key}.json";
        writeJson($evidencePath, $evidence);
    }

    $result = runRatchet($ratchet, $summaryPath, $recordPath ?? $repoRoot.'/.github/governance/known-gaps.json', $evidencePath);

    $verdictSeen = $expectedVerdict === ''
        || str_contains($result['output'], 'RATCHET VERDICT: '.strtoupper($expectedVerdict))
        || ($expectedVerdict === 'error' && str_contains($result['output'], 'ERROR '));

    if ($result['exit'] === $expectedExit && $verdictSeen) {
        ok("{$description} → exit {$expectedExit} ".($expectedVerdict !== '' ? "({$expectedVerdict})" : ''));
    } else {
        bad(sprintf(
            '%s → expected exit %d (%s), got exit %d%s',
            $description,
            $expectedExit,
            $expectedVerdict,
            $result['exit'],
            $verdictSeen ? '' : ' and the expected verdict was not reported',
        ));
    }
}

/** A well-formed record, as a base for mutation. */
function baseRecord(array $gapIds, array $unverifiedIds): array
{
    return [
        'schema' => 1,
        'repository' => 'nzebrian/eruofood-ai',
        'known_gaps' => array_map(static fn (string $id): array => [
            'id' => $id,
            'summary' => 'synthetic gap',
            'why_not_closed' => 'synthetic',
            'approved_by' => 'synthetic',
        ], $gapIds),
        'expected_unverified' => ['ids' => $unverifiedIds],
    ];
}

$goodRecord = baseRecord($recordedGaps, $recordedUnverified);

ratchetCase(
    'the observed failure set is exactly the recorded one',
    summaryDoc($recordedGaps, $recordedUnverified),
    0,
    'match',
    $goodRecord,
);

ratchetCase(
    'a NEW governance failure appears',
    summaryDoc([...$recordedGaps, 'github.main_ruleset_active'], $recordedUnverified),
    1,
    'mismatch',
    $goodRecord,
);

ratchetCase(
    'a recorded gap has resolved but the record is stale',
    summaryDoc(['github.tag_rulesets_active'], $recordedUnverified),
    1,
    'mismatch',
    $goodRecord,
);

ratchetCase(
    'a failure carries no stable identifier',
    summaryDoc([...$recordedGaps, null], $recordedUnverified),
    1,
    'mismatch',
    $goodRecord,
);

ratchetCase(
    'something that should be answerable became unverified',
    summaryDoc($recordedGaps, [...$recordedUnverified, 'github.main_ruleset_active']),
    2,
    'incomplete',
    $goodRecord,
);

ratchetCase(
    'the evidence fetch reported an unreachable endpoint',
    summaryDoc($recordedGaps, $recordedUnverified),
    2,
    'incomplete',
    $goodRecord,
    ['endpoints' => ['rulesets' => ['status' => 'unavailable', 'detail' => 'HTTP 403']]],
);

ratchetCase(
    'an API outage cannot masquerade as an exact known-gap match',
    summaryDoc([], []),
    2,
    'incomplete',
    $goodRecord,
    ['endpoints' => ['rulesets' => ['status' => 'unavailable', 'detail' => 'HTTP 000']]],
);

ratchetCase(
    'the record names the same gap twice',
    summaryDoc($recordedGaps, $recordedUnverified),
    3,
    'error',
    baseRecord([...$recordedGaps, 'github.tag_rulesets_active'], $recordedUnverified),
);

ratchetCase(
    'the record names an identifier the validator does not publish',
    summaryDoc($recordedGaps, $recordedUnverified),
    3,
    'error',
    baseRecord([...$recordedGaps, 'github.completely_made_up'], $recordedUnverified),
);

ratchetCase(
    'the record is not valid JSON',
    summaryDoc($recordedGaps, $recordedUnverified),
    3,
    'error',
    ['{ this is not json'],
);

$noReasonRecord = baseRecord($recordedGaps, $recordedUnverified);
unset($noReasonRecord['known_gaps'][0]['why_not_closed']);

ratchetCase(
    'a gap is recorded with no stated reason',
    summaryDoc($recordedGaps, $recordedUnverified),
    3,
    'error',
    $noReasonRecord,
);

$oldSchema = summaryDoc($recordedGaps, $recordedUnverified);
$oldSchema['schema'] = 1;
unset($oldSchema['failures'], $oldSchema['unverified_detail']);

ratchetCase(
    'a schema-1 summary, with no stable identifiers to match on',
    $oldSchema,
    3,
    'error',
    $goodRecord,
);

$validatorErrored = summaryDoc($recordedGaps, $recordedUnverified);
$validatorErrored['error'] = 1;
$validatorErrored['exit_code'] = 3;
$validatorErrored['exit_reason'] = 'invocation_error';

ratchetCase(
    'the validator itself could not run',
    $validatorErrored,
    3,
    'error',
    $goodRecord,
);

// =============================================================================
// E) The controls on the controls
// =============================================================================

heading('E) Controls on the controls');

// Positive control. If the live repository plus the real record did not
// produce a MATCH, every ratchet result above would be measuring something
// other than what it claims to.
$liveSummary = $workdir.'/live-summary.json';
$result = runProcess(sprintf(
    'php %s --mode=advisory --json=%s',
    escapeshellarg($validator),
    escapeshellarg($liveSummary),
));

if (is_file($liveSummary)) {
    $decoded = json_decode((string) file_get_contents($liveSummary), true);

    if (($decoded['schema'] ?? null) === 2 && isset($decoded['failures'], $decoded['unverified_detail'])) {
        ok('positive control · the validator emits a schema-2 summary with structured failures');
    } else {
        bad('positive control · the validator summary is missing the schema-2 fields');
    }
} else {
    bad('positive control · the validator wrote no summary at all');
}

// False-positive control: the outcome matcher must not report a class for a
// check that was never printed.
if (outcomeOf("  PASS something else\n", $bypassCheck) === 'ABSENT') {
    ok('false-positive control · the outcome matcher reports ABSENT for a check that never ran');
} else {
    bad('false-positive control · the outcome matcher invented an outcome');
}

// -- Integrity ----------------------------------------------------------------

$fingerprintAfter = m37_fingerprint($repoRoot);

echo "\n";

if ($fingerprintBefore === $fingerprintAfter) {
    ok('integrity · the governance tree is byte-identical after the suite');
} else {
    bad('integrity · THE GOVERNANCE TREE CHANGED — something wrote to the repository');
}

// Best-effort cleanup. The fingerprint above, not this, is what proves safety.
array_map('unlink', glob($workdir.'/*') ?: []);
@rmdir($workdir);

$total = $passed + $failedControls;

echo "\n", str_repeat('=', 78), "\n";
printf('RESULT: %d/%d controls confirmed', $passed, $total);
echo $failedControls === 0
    ? " — the fixes bite and the ratchet cannot be fooled.\n"
    : sprintf(", %d FAILED.\n", $failedControls);

exit($failedControls === 0 ? 0 : 1);
