<?php

declare(strict_types=1);

/**
 * M37 Phase 4B — the known-gap ratchet.
 *
 * ## Why this exists
 *
 * With real GitHub evidence the governance validator fails today, for two
 * reasons neither CI nor this repository can fix: the production tag rulesets
 * have never been applied, and CODEOWNERS routes review to nobody. Both are
 * genuine. Both need a human decision, not a code change.
 *
 * That leaves an advisory CI job with two bad options and one good one.
 *
 *   Silence the two checks   — the failure this whole milestone exists to stop.
 *                              A green gate that has been taught not to look.
 *   Ship it permanently red  — GA Docker Certification was red from 2026-08-04
 *                              to 2026-08-24 and blocked nothing, because a
 *                              check that is always red is a check nobody
 *                              reads. Alarm fatigue is not safety.
 *   Ratchet                  — record exactly which failures are accepted, and
 *                              go red on any difference.
 *
 * So this compares the observed failure set against the recorded one and is
 * green only when they match exactly. A new failure turns it red. A recorded
 * failure that has *disappeared* also turns it red, because the record is then
 * describing a repository that no longer exists, and a stale list of accepted
 * risk is more dangerous than no list — it is the document somebody will read
 * in six months to decide what is safe.
 *
 * ## What it refuses to do
 *
 * It never parses console text. It reads the validator's `--json` summary
 * (schema 2+), matches on the stable `failures[].id` identifiers, and treats an
 * unidentified failure as unknown — never as expected.
 *
 * It never converts incomplete verification into success. If evidence could not
 * be fetched, or if something that used to be answerable has stopped being
 * answerable, the verdict is INCOMPLETE (exit 2) and not a match — an API
 * outage must not be able to look like a well-understood repository.
 *
 * Usage:
 *   php scripts/governance_ratchet.php --summary=<validator.json>
 *       [--known-gaps=<path>] [--evidence-status=<path>] [--json=<out>]
 *
 * Exit codes, mirroring the validator's own precedence:
 *   0  MATCH       observed failures == recorded gaps, verification as expected
 *   1  MISMATCH    a new failure, or a recorded gap that has gone stale
 *   2  INCOMPLETE  evidence unavailable, or something became unverifiable
 *   3  ERROR       bad invocation, or an unusable known-gap record
 */

const RATCHET_MATCH = 0;
const RATCHET_MISMATCH = 1;
const RATCHET_INCOMPLETE = 2;
const RATCHET_ERROR = 3;

const RATCHET_SCHEMA = 1;

/** The lowest validator summary schema that carries `failures[].id`. */
const MIN_SUMMARY_SCHEMA = 2;

$repoRoot = dirname(__DIR__, 3);

$summaryPath = null;
$knownGapsPath = $repoRoot.'/.github/governance/known-gaps.json';
$evidenceStatusPath = null;
$outPath = null;

function ratchetError(string $message, ?string $outPath = null): never
{
    fprintf(STDERR, "ERROR  %s\n", $message);
    fprintf(STDERR, "       usage: governance_ratchet.php --summary=<path>\n");
    fprintf(STDERR, "              [--known-gaps=<path>] [--evidence-status=<path>] [--json=<path>]\n");

    if ($outPath !== null) {
        @file_put_contents($outPath, json_encode([
            'schema' => RATCHET_SCHEMA,
            'verdict' => 'error',
            'exit_code' => RATCHET_ERROR,
            'reason' => $message,
            'expected_failures' => [],
            'observed_failures' => [],
            'unexpected_failures' => [],
            'stale_recorded_gaps' => [],
            'unexpected_unverified' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    exit(RATCHET_ERROR);
}

$argList = $GLOBALS['argv'] ?? [];
array_shift($argList);

// Two passes, so --json is known before an unknown flag aborts and the
// machine-readable result can describe the invocation error too.
foreach ($argList as $arg) {
    if (str_starts_with((string) $arg, '--json=')) {
        $outPath = substr((string) $arg, strlen('--json='));
    }
}

/** Pull a flag's value, refusing an empty one. Null when the flag is not this. */
function flagValue(string $arg, string $flag, ?string $outPath): ?string
{
    if (! str_starts_with($arg, $flag)) {
        return null;
    }

    $value = substr($arg, strlen($flag));

    if ($value === '') {
        ratchetError("{$flag} was given an empty path", $outPath);
    }

    return $value;
}

foreach ($argList as $rawArg) {
    $arg = (string) $rawArg;

    if (($value = flagValue($arg, '--summary=', $outPath)) !== null) {
        $summaryPath = $value;

        continue;
    }

    if (($value = flagValue($arg, '--known-gaps=', $outPath)) !== null) {
        $knownGapsPath = $value;

        continue;
    }

    if (($value = flagValue($arg, '--evidence-status=', $outPath)) !== null) {
        $evidenceStatusPath = $value;

        continue;
    }

    // Already captured in the first pass; validated here so an empty value is
    // still an invocation error rather than a silently ignored flag.
    if (flagValue($arg, '--json=', $outPath) !== null) {
        continue;
    }

    ratchetError("unrecognised argument: {$arg}", $outPath);
}

if ($summaryPath === null) {
    ratchetError('--summary=<validator json> is required', $outPath);
}

/** Decode a JSON file, or abort with a reason rather than a stack trace. */
function ratchetJson(string $label, string $path, ?string $outPath): array
{
    if (! is_file($path)) {
        ratchetError("{$label} not found: {$path}", $outPath);
    }

    $raw = @file_get_contents($path);

    if ($raw === false || trim((string) $raw) === '') {
        ratchetError("{$label} is empty or unreadable: {$path}", $outPath);
    }

    try {
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        ratchetError("{$label} is not valid JSON: {$e->getMessage()}", $outPath);
    }

    if (! is_array($decoded) || array_is_list($decoded)) {
        ratchetError("{$label} must be a JSON object", $outPath);
    }

    return $decoded;
}

// -- The known-gap record -----------------------------------------------------
//
// Held to a shape before it is believed. A record that cannot be trusted must
// not be able to wave a governance failure through, so every defect here is an
// ERROR rather than a soft default.

$record = ratchetJson('known-gaps record', $knownGapsPath, $outPath);

if (($record['schema'] ?? null) !== 1) {
    ratchetError(
        'known-gaps record has unsupported schema: '.json_encode($record['schema'] ?? null),
        $outPath,
    );
}

if (! isset($record['known_gaps']) || ! is_array($record['known_gaps']) || ! array_is_list($record['known_gaps'])) {
    ratchetError('known-gaps record: `known_gaps` must be a JSON array', $outPath);
}

$expectedFailures = [];

foreach ($record['known_gaps'] as $index => $gap) {
    if (! is_array($gap) || array_is_list($gap)) {
        ratchetError("known-gaps record: entry {$index} is not an object", $outPath);
    }

    $id = $gap['id'] ?? null;

    if (! is_string($id) || trim($id) === '') {
        ratchetError("known-gaps record: entry {$index} has no usable `id`", $outPath);
    }

    // A duplicate is not harmless. Two entries for one identifier means two
    // people recorded the same accepted risk independently, and deleting one
    // would silently leave the gap accepted — the reviewer would believe they
    // had closed it.
    if (in_array($id, $expectedFailures, true)) {
        ratchetError("known-gaps record: duplicate identifier `{$id}`", $outPath);
    }

    // An accepted risk with no stated reason is not an accepted risk; it is an
    // unexplained exemption, and it is exactly what this file must not become.
    foreach (['summary', 'why_not_closed', 'approved_by'] as $required) {
        if (! isset($gap[$required]) || ! is_string($gap[$required]) || trim($gap[$required]) === '') {
            ratchetError("known-gaps record: entry `{$id}` is missing `{$required}`", $outPath);
        }
    }

    $expectedFailures[] = $id;
}

$expectedUnverified = [];
$unverifiedBlock = $record['expected_unverified'] ?? null;

if ($unverifiedBlock !== null) {
    if (! is_array($unverifiedBlock) || ! isset($unverifiedBlock['ids']) || ! is_array($unverifiedBlock['ids'])) {
        ratchetError('known-gaps record: `expected_unverified.ids` must be a JSON array', $outPath);
    }

    foreach ($unverifiedBlock['ids'] as $id) {
        if (! is_string($id) || trim($id) === '') {
            ratchetError('known-gaps record: `expected_unverified.ids` contains a non-string', $outPath);
        }

        if (in_array($id, $expectedUnverified, true)) {
            ratchetError("known-gaps record: duplicate identifier `{$id}` in expected_unverified", $outPath);
        }

        $expectedUnverified[] = $id;
    }
}

// -- The validator summary ----------------------------------------------------

$summary = ratchetJson('validator summary', $summaryPath, $outPath);

$summarySchema = $summary['schema'] ?? null;

if (! is_int($summarySchema) || $summarySchema < MIN_SUMMARY_SCHEMA) {
    ratchetError(
        'validator summary schema '.json_encode($summarySchema).' is too old; '
        .'the ratchet needs schema '.MIN_SUMMARY_SCHEMA.'+ for stable failure identifiers',
        $outPath,
    );
}

foreach (['failed', 'external_unverified', 'exit_code'] as $field) {
    if (! isset($summary[$field]) || ! is_int($summary[$field])) {
        ratchetError("validator summary is missing integer field `{$field}`", $outPath);
    }
}

foreach (['failures', 'unverified_detail', 'check_ids'] as $field) {
    if (! isset($summary[$field]) || ! is_array($summary[$field]) || ! array_is_list($summary[$field])) {
        ratchetError("validator summary is missing list field `{$field}`", $outPath);
    }
}

// The validator could not run at all. Nothing downstream of that means
// anything, and calling it a ratchet mismatch would point the reader at the
// wrong problem.
if (($summary['error'] ?? 0) !== 0 || $summary['exit_code'] === 3) {
    ratchetError(
        'the validator reported an error ('.(string) ($summary['exit_reason'] ?? 'unknown').'); nothing to ratchet',
        $outPath,
    );
}

/** @return array{list<string>, int} identifiers, and how many had none */
function idsOf(array $items): array
{
    $ids = [];
    $anonymous = 0;

    foreach ($items as $item) {
        $id = is_array($item) ? ($item['id'] ?? null) : null;

        if (! is_string($id) || trim($id) === '') {
            $anonymous++;

            continue;
        }

        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return [$ids, $anonymous];
}

[$observedFailures, $anonymousFailures] = idsOf($summary['failures']);
[$observedUnverified, $anonymousUnverified] = idsOf($summary['unverified_detail']);

// -- Cross-check the record against reality -----------------------------------
//
// A recorded identifier the validator has never heard of means the record is
// wrong — a typo, or a check that was renamed without updating this file. Left
// unchecked it would silently excuse nothing while looking like it excused
// something.
//
// The Phase 4B review found this list duplicated here as string literals, with
// nothing asserting it matched the validator's CHECK_* constants. It now comes
// from the summary's `check_ids`, which the validator emits from the single
// definition of that set, so the two cannot drift apart. Absent or malformed,
// it is an ERROR rather than a fallback: guessing the identifier set is how the
// check would quietly stop meaning anything.

$publishedIds = $summary['check_ids'] ?? null;

if (! is_array($publishedIds) || ! array_is_list($publishedIds) || $publishedIds === []) {
    ratchetError(
        'validator summary carries no usable `check_ids`; without the authoritative '
        .'identifier set the ratchet cannot tell a real check from a typo',
        $outPath,
    );
}

foreach ($publishedIds as $id) {
    if (! is_string($id) || trim($id) === '') {
        ratchetError('validator summary `check_ids` contains a non-string identifier', $outPath);
    }
}

// Anything the validator actually reported must be in its own published set.
// If it is not, the summary is internally inconsistent and nothing downstream
// of it can be trusted.
foreach (array_merge($observedFailures, $observedUnverified) as $id) {
    if (! in_array($id, $publishedIds, true)) {
        ratchetError(
            "the summary reports `{$id}`, which is absent from its own `check_ids` — the summary is inconsistent",
            $outPath,
        );
    }
}

foreach (array_merge($expectedFailures, $expectedUnverified) as $id) {
    if (! in_array($id, $publishedIds, true)) {
        ratchetError(
            "known-gaps record names `{$id}`, which is not a check identifier this validator publishes",
            $outPath,
        );
    }
}

// -- Verdict ------------------------------------------------------------------

$unexpectedFailures = array_values(array_diff($observedFailures, $expectedFailures));
$staleGaps = array_values(array_diff($expectedFailures, $observedFailures));
$unexpectedUnverified = array_values(array_diff($observedUnverified, $expectedUnverified));

$evidenceProblems = [];

if ($evidenceStatusPath !== null) {
    $status = ratchetJson('evidence status', $evidenceStatusPath, $outPath);

    foreach (($status['endpoints'] ?? []) as $name => $detail) {
        $state = is_array($detail) ? ($detail['status'] ?? null) : null;

        if ($state !== 'ok') {
            $evidenceProblems[] = sprintf(
                '%s: %s (%s)',
                (string) $name,
                (string) ($state ?? 'unknown'),
                (string) (is_array($detail) ? ($detail['detail'] ?? 'no detail') : 'no detail'),
            );
        }
    }
}

// Precedence. Incomplete verification outranks a mismatch, because a mismatch
// computed from evidence we know to be partial is not a finding — it is noise
// that would send somebody looking for a governance change that never happened.
if ($evidenceProblems !== []) {
    $verdict = 'incomplete';
    $exitCode = RATCHET_INCOMPLETE;
    $reason = 'evidence could not be fetched: '.implode('; ', $evidenceProblems);
} elseif ($unexpectedUnverified !== [] || $anonymousUnverified > 0) {
    $verdict = 'incomplete';
    $exitCode = RATCHET_INCOMPLETE;
    $reason = 'verification got worse: '
        .($unexpectedUnverified !== [] ? implode(', ', $unexpectedUnverified) : '')
        .($anonymousUnverified > 0 ? " plus {$anonymousUnverified} unverified item(s) with no identifier" : '');
} elseif ($anonymousFailures > 0) {
    $verdict = 'mismatch';
    $exitCode = RATCHET_MISMATCH;
    $reason = "{$anonymousFailures} failure(s) carry no stable identifier and cannot be matched against the record";
} elseif ($unexpectedFailures !== []) {
    $verdict = 'mismatch';
    $exitCode = RATCHET_MISMATCH;
    $reason = 'new governance failure(s) not in the record: '.implode(', ', $unexpectedFailures);
} elseif ($staleGaps !== []) {
    $verdict = 'mismatch';
    $exitCode = RATCHET_MISMATCH;
    $reason = 'recorded gap(s) no longer observed — the record is stale and must be updated: '
        .implode(', ', $staleGaps);
} else {
    $verdict = 'match';
    $exitCode = RATCHET_MATCH;
    $reason = 'the observed governance failure set is exactly the recorded one';
}

// -- Report -------------------------------------------------------------------

echo "EruoFood — governance known-gap ratchet\n";
echo str_repeat('=', 72), "\n";
printf("known-gaps record: %s\n", $knownGapsPath);
printf("validator summary: %s (schema %d, mode %s)\n", $summaryPath, $summarySchema, (string) ($summary['mode'] ?? '?'));

$show = static function (string $label, array $ids): void {
    printf("\n%s (%d)\n", $label, count($ids));

    foreach ($ids as $id) {
        printf("  - %s\n", $id);
    }

    if ($ids === []) {
        echo "  (none)\n";
    }
};

$show('EXPECTED failures (recorded and accepted)', $expectedFailures);
$show('OBSERVED failures (live)', $observedFailures);
$show('UNEXPECTED failures (new — nobody has accepted these)', $unexpectedFailures);
$show('STALE recorded gaps (recorded, but no longer failing)', $staleGaps);
$show('UNEXPECTED unverified (verification got worse)', $unexpectedUnverified);

if ($evidenceProblems !== []) {
    printf("\nEVIDENCE PROBLEMS (%d)\n", count($evidenceProblems));

    foreach ($evidenceProblems as $problem) {
        printf("  - %s\n", $problem);
    }
}

echo "\n", str_repeat('=', 72), "\n";
printf("RATCHET VERDICT: %s — %s\n", strtoupper($verdict), $reason);

if ($verdict === 'match') {
    echo "\nThis is NOT a statement that governance is healthy. Two failures are\n";
    echo "live and accepted; see .github/governance/known-gaps.json for what they\n";
    echo "are, who accepted them, and how to close them.\n";
}

if ($outPath !== null) {
    $payload = [
        'schema' => RATCHET_SCHEMA,
        'verdict' => $verdict,
        'exit_code' => $exitCode,
        'reason' => $reason,
        'expected_failures' => $expectedFailures,
        'observed_failures' => $observedFailures,
        'unexpected_failures' => $unexpectedFailures,
        'stale_recorded_gaps' => $staleGaps,
        'expected_unverified' => $expectedUnverified,
        'observed_unverified' => $observedUnverified,
        'unexpected_unverified' => $unexpectedUnverified,
        'anonymous_failures' => $anonymousFailures,
        'anonymous_unverified' => $anonymousUnverified,
        'evidence_problems' => $evidenceProblems,
        'validator' => [
            'mode' => $summary['mode'] ?? null,
            'failed' => $summary['failed'],
            'external_unverified' => $summary['external_unverified'],
            'skipped' => $summary['skipped'] ?? null,
            'exit_code' => $summary['exit_code'],
            'exit_reason' => $summary['exit_reason'] ?? null,
        ],
    ];

    if (@file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
        fprintf(STDERR, "ERROR  could not write --json result to %s\n", $outPath);

        exit(RATCHET_ERROR);
    }
}

exit($exitCode);
