<?php

declare(strict_types=1);

/**
 * M42 — do the retention safeguards actually discriminate?
 *
 * ## Why a passing validator is not evidence
 *
 * `verify_retention_enforcement.php` printed twenty-two PASS lines the first
 * time it ran. So would a validator whose checks were all `str_contains($php,
 * '')`. A green control suite proves the properties hold only if the same suite
 * goes red when they stop holding — otherwise it is a decoration that costs CI
 * minutes and buys confidence it has not earned.
 *
 * So each control below breaks exactly one safeguard and requires the validator
 * to fail **on the specific check that safeguard belongs to**. A bare non-zero
 * exit is not accepted: the validator exits 1 for any retention failure and 3
 * for a misinvocation, so a control asserting only "it failed" can pass while
 * the mutation it injected did nothing at all and something unrelated broke.
 *
 * ## Why the real repository is never touched
 *
 * The M37 audit's lesson, applied here from the start. Mutating tracked files
 * and restoring them in a `finally` works right up until the process dies on a
 * fatal error, an OOM, or the SIGTERM a cancelled CI job receives — any of which
 * leaves a deliberately-broken retention safeguard committed to somebody's
 * working tree.
 *
 * Every mutation here happens inside a unique `mktemp` fixture holding copies of
 * exactly the files the validator reads, pointed at with `--repo-root=`. The
 * real tree is fingerprinted with sha256 before and after and the run fails if a
 * single byte moved. `finally` is still used, but only to delete a fixture.
 *
 * Run: php scripts/m42_retention_negative_control.php
 */

require_once __DIR__.'/verify_retention_enforcement_sources.php';

const M42_VALIDATOR = __DIR__.'/verify_retention_enforcement.php';

/** Exit codes the validator uses. Mirrored, not guessed. */
const M42_EXIT_FAIL = 1;

function m42_repo_root(): string
{
    return dirname(__DIR__, 3);
}

/**
 * A deterministic sha256 manifest of every file the controls could possibly
 * reach.
 *
 * Content-addressed and sorted, so it changes if any byte changes, if a file
 * appears, or if one disappears.
 */
function m42_fingerprint(): string
{
    $root = m42_repo_root();
    $entries = [];

    foreach (m42_retention_sources() as $relative) {
        $absolute = $root.'/'.$relative;
        $entries[$relative] = is_file($absolute) ? hash_file('sha256', $absolute) : 'ABSENT';
    }

    ksort($entries);

    $manifest = '';
    foreach ($entries as $path => $hash) {
        $manifest .= $hash.'  '.$path."\n";
    }

    return hash('sha256', $manifest);
}

/**
 * A pristine fixture: a temporary tree holding copies of exactly the files the
 * validator reads, at their repository-relative paths.
 */
function m42_make_fixture(): string
{
    $base = sys_get_temp_dir().'/m42-retention-'.bin2hex(random_bytes(8));

    if (! mkdir($base, 0o700, true)) {
        throw new RuntimeException("could not create fixture directory {$base}");
    }

    $root = m42_repo_root();

    foreach (m42_retention_sources() as $relative) {
        $source = $root.'/'.$relative;

        if (is_link($source)) {
            // A symlink copied into a fixture would let a mutation reach through
            // it into the real repository — the exact escape this design exists
            // to prevent.
            throw new RuntimeException("refusing to copy a symlink into a fixture: {$source}");
        }

        if (! is_file($source)) {
            throw new RuntimeException("fixture source is missing: {$source}");
        }

        $destination = $base.'/'.$relative;

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0o755, true);
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("could not copy {$source}");
        }
    }

    return $base;
}

/** Assert that nothing inside the fixture resolves outside it. */
function m42_assert_contained(string $fixture): void
{
    $real = realpath($fixture);

    if ($real === false) {
        throw new RuntimeException("fixture vanished: {$fixture}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            throw new RuntimeException("fixture contains a symlink: {$entry->getPathname()}");
        }

        $resolved = realpath($entry->getPathname());

        if ($resolved === false || ! str_starts_with($resolved, $real.'/')) {
            throw new RuntimeException("fixture path escapes its root: {$entry->getPathname()}");
        }
    }
}

function m42_rmtree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        m42_rmtree($path.'/'.$entry);
    }

    @rmdir($path);
}

/**
 * Apply one mutation inside the fixture.
 *
 * A `find` string that is not present is a hard error, not a skipped control.
 * A control whose mutation silently did nothing would report the validator as
 * discriminating when it never saw a change — the single most misleading
 * outcome this whole file exists to prevent.
 */
function m42_mutate(string $fixture, string $relative, string $find, string $replace): void
{
    $path = $fixture.'/'.$relative;
    $original = file_get_contents($path);

    if ($original === false) {
        throw new RuntimeException("cannot read fixture file {$relative}");
    }

    $count = substr_count($original, $find);

    if ($count === 0) {
        throw new RuntimeException("mutation target not found in {$relative}: ".substr($find, 0, 90));
    }

    if ($count > 1) {
        throw new RuntimeException("mutation target is ambiguous ({$count} matches) in {$relative}: ".substr($find, 0, 90));
    }

    if (file_put_contents($path, str_replace($find, $replace, $original)) === false) {
        throw new RuntimeException("cannot write fixture file {$relative}");
    }
}

/**
 * Run the validator against a fixture.
 *
 * @return array{exit: int, output: string, failed_ids: list<string>}
 */
function m42_run_validator(string $fixture): array
{
    $summaryPath = $fixture.'.summary.json';

    $command = 'php '.escapeshellarg(M42_VALIDATOR)
        .' --repo-root='.escapeshellarg($fixture)
        .' --json='.escapeshellarg($summaryPath)
        .' 2>&1';

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    $failedIds = [];

    if (is_file($summaryPath)) {
        $decoded = json_decode((string) file_get_contents($summaryPath), true);

        if (is_array($decoded)) {
            foreach ($decoded['failures'] ?? [] as $failure) {
                if (is_array($failure) && isset($failure['id'])) {
                    $failedIds[] = (string) $failure['id'];
                }
            }
        }

        @unlink($summaryPath);
    }

    return ['exit' => $exit, 'output' => implode("\n", $output), 'failed_ids' => $failedIds];
}

/*
 * Each control: what safeguard it removes, the edit that removes it, and the
 * validator check that must go red as a result.
 */
$controls = [
    [
        'name' => '1. idempotency eligibility reversed from expires_at to created_at',
        'expect' => 'retention.idempotency_not_created_at',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Infrastructure/Idempotency/EloquentIdempotencyStore.php',
            'find' => "                ->where('expires_at', '<', \$cutoff)",
            'replace' => "                ->where('created_at', '<', \$cutoff)",
        ]],
    ],
    [
        'name' => '2. the idempotency command grows a --days age window',
        'expect' => 'retention.idempotency_no_days_option',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Infrastructure/Console/PurgeIdempotencyKeysCommand.php',
            'find' => "        {--chunk= : Rows to delete per statement}",
            'replace' => "        {--days= : Delete claims older than this}\n        {--chunk= : Rows to delete per statement}",
        ]],
    ],
    [
        'name' => '3. --dry-run removed from a purge command',
        'expect' => 'retention.dry_run_option_present',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Console/PurgeRiderLocationsCommand.php',
            'find' => "        {--dry-run : Report what would be removed, and remove nothing}",
            'replace' => '',
        ]],
    ],
    [
        'name' => '4. the dry-run branch made destructive',
        'expect' => 'retention.dry_run_is_non_destructive',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Infrastructure/Console/PurgeIdempotencyKeysCommand.php',
            'find' => "            if (\$this->option('dry-run')) {\n                \$this->line(sprintf(",
            'replace' => "            if (\$this->option('dry-run')) {\n                \$store->purgeExpired(\$chunk);\n                \$this->line(sprintf(",
        ]],
    ],
    [
        'name' => '5. a zero or negative retention window permitted',
        'expect' => 'retention.window_must_be_positive',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Console/PurgeRiderLocationsCommand.php',
            'find' => '        if ($days <= 0) {',
            'replace' => '        if (false) {',
        ]],
    ],
    [
        'name' => '6. a zero or negative chunk permitted',
        'expect' => 'retention.chunk_must_be_positive',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Infrastructure/Console/PurgeIdempotencyKeysCommand.php',
            'find' => '        if ($chunk <= 0) {',
            'replace' => '        if (false) {',
        ]],
    ],
    [
        'name' => '7. chunking removed from the delete loop',
        'expect' => 'retention.deletion_is_chunked',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Persistence/Eloquent/EloquentRiderLocationRepository.php',
            'find' => "                ->limit(\$chunkSize)\n",
            'replace' => '',
        ]],
    ],
    [
        'name' => '8. an unbounded DELETE introduced',
        'expect' => 'retention.no_unbounded_delete',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Persistence/Eloquent/EloquentRiderLocationRepository.php',
            'find' => "            \$removed += RiderLocationModel::query()->whereIn('rider_id', \$ids)->delete();",
            'replace' => "            \$removed += RiderLocationModel::query()->where('recorded_at', '<', \$cutoff)->delete();",
        ]],
    ],
    [
        'name' => '9. an Anonymise policy converted to Destroy',
        'expect' => 'retention.anonymise_stays_anonymise',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionRegistry.php',
            'find' => 'deletionMode: DeletionMode::Anonymise,',
            'replace' => 'deletionMode: DeletionMode::Destroy,',
        ]],
    ],
    [
        'name' => '10. the enforcement path removed for a non-indefinite policy',
        'expect' => 'retention.coverage_complete',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionEnforcement.php',
            'find' => "        'geo.rider_locations' => 'geo:purge-rider-locations',\n",
            'replace' => '',
        ]],
    ],
    [
        'name' => '11. coverage made to fail open for an unknown policy',
        'expect' => 'retention.coverage_fails_closed',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionEnforcement.php',
            'find' => '        throw new InvalidArgumentException(',
            'replace' => "        return null;\n\n        throw new InvalidArgumentException(",
        ]],
    ],
    [
        'name' => '12. an exemption left without a written reason',
        'expect' => 'retention.exemptions_are_reasoned',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionEnforcement.php',
            'find' => '    private const array EXEMPT = [',
            'replace' => "    private const array EXEMPT = [\n        'search.query_log' => '',\n",
        ]],
    ],
    [
        'name' => '13. the scheduler bypasses lifecycle.retention_purge',
        'expect' => 'retention.gate_applied_in_bootstrap',
        'mutations' => [[
            'file' => 'apps/api/bootstrap/app.php',
            'find' => '            if ($task->destructiveRetention && ! $retention->allowsScheduledPurge()) {',
            'replace' => '            if (false) {',
        ]],
    ],
    [
        'name' => '14. the gate short-circuits to open',
        'expect' => 'retention.gate_reads_master_flag',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionGate.php',
            'find' => 'return $this->flags->isEnabled(self::FLAG);',
            'replace' => 'return true;',
        ]],
    ],
    [
        'name' => '15. the master flag given an unsafe default',
        'expect' => 'retention.master_flag_safe_default',
        'mutations' => [[
            'file' => 'apps/api/modules/Shared/src/Infrastructure/Provider/SharedServiceProvider.php',
            'find' => "                key: 'lifecycle.retention_purge',\n                safeDefault: false,",
            'replace' => "                key: 'lifecycle.retention_purge',\n                safeDefault: true,",
        ]],
    ],
    [
        'name' => '16. configuration ships the master flag on',
        'expect' => 'retention.master_flag_not_forced_on',
        'mutations' => [[
            'file' => 'apps/api/config/flags.php',
            'find' => "'lifecycle.retention_purge' => env('FLAG_LIFECYCLE_RETENTION_PURGE'),",
            'replace' => "'lifecycle.retention_purge' => env('FLAG_LIFECYCLE_RETENTION_PURGE', true),",
        ]],
    ],
    [
        'name' => '17. a retention schedule enabled by default',
        'expect' => 'retention.schedules_disabled',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Provider/GeoServiceProvider.php',
            'find' => '            enabled: false,',
            'replace' => '            enabled: true,',
        ]],
    ],
    [
        'name' => '18. a purge task left unmarked, escaping the gate entirely',
        'expect' => 'retention.schedules_marked_destructive',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Provider/GeoServiceProvider.php',
            'find' => '            destructiveRetention: true,',
            'replace' => '            withoutOverlapping: true,',
        ]],
    ],
    [
        'name' => '19. a command prints the values it is deleting',
        'expect' => 'retention.output_has_no_sensitive_fields',
        'mutations' => [[
            'file' => 'apps/api/modules/Geo/src/Infrastructure/Console/PurgeRiderLocationsCommand.php',
            'find' => "            \$removed = \$riders->purgeRecordedBefore(\$cutoff, \$chunk);",
            'replace' => "            \$removed = \$riders->purgeRecordedBefore(\$cutoff, \$chunk);\n            \$this->line('rider_id and latitude of each purged row');",
        ]],
    ],
];

// =============================================================================

echo "EruoFood — M42 retention negative controls\n";
echo str_repeat('=', 78)."\n";
echo "Each safeguard is removed inside a disposable fixture; the validator must\n";
echo "then fail on that safeguard's own check.\n\n";

$before = m42_fingerprint();
echo "Protected-tree fingerprint (before): {$before}\n\n";

$confirmed = 0;
$falsePositives = [];
$brokenControls = [];

foreach ($controls as $control) {
    printf('%-70s', substr($control['name'], 0, 70));

    $fixture = null;

    try {
        $fixture = m42_make_fixture();
        m42_assert_contained($fixture);

        foreach ($control['mutations'] as $mutation) {
            m42_mutate($fixture, $mutation['file'], $mutation['find'], $mutation['replace']);
        }

        $result = m42_run_validator($fixture);

        if ($result['exit'] !== M42_EXIT_FAIL) {
            $falsePositives[] = $control['name'].' (validator exited '.$result['exit'].', expected '.M42_EXIT_FAIL.')';
            echo " FALSE POSITIVE\n";

            continue;
        }

        if (! in_array($control['expect'], $result['failed_ids'], true)) {
            // It failed, but not for the reason the control injected. That is a
            // different defect and must not be counted as confirmation.
            $falsePositives[] = sprintf(
                '%s (failed on [%s], expected [%s])',
                $control['name'],
                implode(', ', $result['failed_ids']) ?: 'nothing',
                $control['expect'],
            );
            echo " WRONG CHECK\n";

            continue;
        }

        $confirmed++;
        echo " ok\n";
    } catch (Throwable $e) {
        $brokenControls[] = $control['name'].': '.$e->getMessage();
        echo " BROKEN\n";
    } finally {
        if ($fixture !== null) {
            m42_rmtree($fixture);
        }
    }
}

// Control 20 — the positive control. An unmutated fixture must PASS. Without
// this, every control above is satisfied by a validator that fails on
// everything, including a correct repository.
printf('%-70s', '20. positive control: an unmutated fixture passes');

$fixture = null;
$positiveOk = false;

try {
    $fixture = m42_make_fixture();
    m42_assert_contained($fixture);
    $result = m42_run_validator($fixture);
    $positiveOk = $result['exit'] === 0;

    echo $positiveOk ? " ok\n" : " FAILED\n";

    if (! $positiveOk) {
        echo "\n".$result['output']."\n";
    }
} catch (Throwable $e) {
    $brokenControls[] = 'positive control: '.$e->getMessage();
    echo " BROKEN\n";
} finally {
    if ($fixture !== null) {
        m42_rmtree($fixture);
    }
}

// Control 21 — integrity. Everything above mutated a copy; the real tree must be
// byte-identical to what it was before the run.
printf('%-70s', '21. sha256 integrity: the real repository is unchanged');

$after = m42_fingerprint();
$integrityOk = $before === $after;

echo $integrityOk ? " ok\n" : " FAILED\n";

echo "\nProtected-tree fingerprint (after):  {$after}\n";

echo "\n".str_repeat('=', 78)."\n";
printf("%d/%d mutations confirmed by the check they targeted.\n", $confirmed, count($controls));

if ($brokenControls !== []) {
    echo "\nBROKEN CONTROLS (the control itself needs updating):\n";
    foreach ($brokenControls as $name) {
        echo "  - {$name}\n";
    }
}

if ($falsePositives !== []) {
    echo "\nFALSE POSITIVES — the validator did not discriminate:\n";
    foreach ($falsePositives as $name) {
        echo "  - {$name}\n";
    }
}

if (! $integrityOk) {
    echo "\nINTEGRITY FAILURE — the real repository changed during this run.\n";
}

$ok = $confirmed === count($controls) && $brokenControls === [] && $falsePositives === [] && $positiveOk && $integrityOk;

echo $ok
    ? "\nAll retention safeguards discriminate, and the working tree is untouched.\n"
    : "\nRetention negative controls FAILED.\n";

exit($ok ? 0 : 1);
