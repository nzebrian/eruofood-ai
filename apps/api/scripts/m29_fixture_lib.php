<?php

declare(strict_types=1);

/**
 * M37 — the fixture harness the three M29 negative controls share.
 *
 * ## What this replaces, and why
 *
 * Until M37 each control mutated the REAL repository — deleting
 * `.github/governance/BREAK_GLASS.md`, rewriting `main-ruleset.json`, appending
 * to `.github/CODEOWNERS` — and restored it in a `finally`. That works right up
 * until it does not. PHP runs `finally` on a normal return and on an exception;
 * it does not run it on a fatal error, on `exit()`, on OOM, or on the SIGTERM a
 * cancelled CI job receives. Any of those leaves governance artifacts corrupted
 * on disk, and the restore itself was an unchecked `file_put_contents` whose
 * failure was silent.
 *
 * The M37 audit also found four targets shared between the three scripts
 * (`CODEOWNERS`, `identities.json`, `main-ruleset.json`,
 * `production-tags-ruleset.json`) with no locking, so two controls running
 * concurrently could restore each other's mutation.
 *
 * So the real repository is now read-only for these controls. Every mutation
 * happens inside a unique `mktemp` fixture, the validator is pointed at it with
 * `--repo-root=`, and the suite fingerprints the real governance tree before
 * and after to prove it. `finally` is still used — but only to delete a
 * fixture, never to protect anything that matters.
 *
 * ## Why a control needs more than a non-zero exit
 *
 * `$exit !== 0` is not proof. The validator exits non-zero for a governance
 * failure (1), for unverified evidence in strict mode (2) and for a bad
 * invocation (3) — so a control asserting only "it failed" can pass while the
 * mutation it injected did nothing at all. Every control here asserts that the
 * validator actually ran, that the SPECIFIC expected check failed, that the
 * exit code is the governance-failure code, and that the machine-readable
 * summary attributes the outcome to a real failure rather than to unverified
 * external evidence.
 */

/** Paths the validator reads. A fixture that omits one is incomplete. */
const M29_FIXTURE_PATHS = [
    '.github/governance',
    '.github/CODEOWNERS',
    '.github/workflows',
];

/** The real tree these controls must never touch. */
const M29_PROTECTED_PATHS = [
    '.github/governance',
    '.github/CODEOWNERS',
    '.github/workflows',
];

function m29_repo_root(): string
{
    return dirname(__DIR__, 3);
}

function m29_validator(): string
{
    return __DIR__.'/verify_repository_governance.php';
}

/**
 * A deterministic sha256 manifest of the real protected tree.
 *
 * Sorted, path-relative and content-addressed, so it changes if any byte of any
 * protected file changes, if a file appears, or if one disappears.
 */
function m29_fingerprint(): string
{
    $root = m29_repo_root();
    $entries = [];

    foreach (M29_PROTECTED_PATHS as $relative) {
        $absolute = $root.'/'.$relative;

        if (is_file($absolute)) {
            $entries[$relative] = hash_file('sha256', $absolute);

            continue;
        }

        if (! is_dir($absolute)) {
            $entries[$relative] = 'ABSENT';

            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $key = substr($file->getPathname(), strlen($root) + 1);
            $entries[$key] = hash_file('sha256', $file->getPathname());
        }
    }

    ksort($entries);

    $manifest = '';
    foreach ($entries as $path => $hash) {
        $manifest .= $hash.'  '.$path."\n";
    }

    return hash('sha256', $manifest);
}

/** Recursive copy that refuses symlinks rather than following them. */
function m29_copy(string $source, string $destination): void
{
    if (is_link($source)) {
        // Copying a symlink into a fixture would let a mutation reach through
        // it into the real repository — the precise escape this design exists
        // to prevent.
        throw new RuntimeException("refusing to copy a symlink into a fixture: {$source}");
    }

    if (is_file($source)) {
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0o755, true);
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("could not copy {$source}");
        }

        return;
    }

    if (! is_dir($source)) {
        return;
    }

    if (! is_dir($destination)) {
        mkdir($destination, 0o755, true);
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        m29_copy($source.'/'.$entry, $destination.'/'.$entry);
    }
}

/**
 * A pristine fixture: a unique temporary directory holding a copy of exactly
 * the tree the validator reads.
 *
 * @param list<string> $omit relative paths to leave out, for completeness controls
 */
function m29_make_fixture(array $omit = []): string
{
    $base = sys_get_temp_dir().'/m29-fixture-'.bin2hex(random_bytes(8));

    if (! mkdir($base, 0o700, true)) {
        throw new RuntimeException("could not create fixture directory {$base}");
    }

    $root = m29_repo_root();

    foreach (M29_FIXTURE_PATHS as $relative) {
        if (in_array($relative, $omit, true)) {
            continue;
        }

        m29_copy($root.'/'.$relative, $base.'/'.$relative);
    }

    return $base;
}

/**
 * Assert that nothing inside the fixture resolves outside it.
 *
 * A copied tree should contain no links at all; this proves it rather than
 * assuming it, because a single symlink would turn every "fixture-only"
 * mutation below into a write against the real repository.
 */
function m29_assert_contained(string $fixture): void
{
    $realFixture = realpath($fixture);

    if ($realFixture === false) {
        throw new RuntimeException("fixture vanished: {$fixture}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realFixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            throw new RuntimeException("fixture contains a symlink: {$entry->getPathname()}");
        }

        $resolved = realpath($entry->getPathname());

        if ($resolved === false || ! str_starts_with($resolved, $realFixture.'/')) {
            throw new RuntimeException("fixture path escapes its root: {$entry->getPathname()}");
        }
    }
}

function m29_rmtree(string $path): void
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

        m29_rmtree($path.'/'.$entry);
    }

    @rmdir($path);
}

/**
 * Run the validator against a fixture and return everything a control needs to
 * judge the outcome.
 *
 * @param list<string> $extraArgs
 *
 * @return array{exit:int, output:string, summary:?array<string, mixed>}
 */
function m29_run_validator(string $fixture, array $extraArgs = []): array
{
    $summaryPath = $fixture.'.summary.json';

    $command = 'php '.escapeshellarg(m29_validator())
        .' --repo-root='.escapeshellarg($fixture)
        .' --json='.escapeshellarg($summaryPath);

    foreach ($extraArgs as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }

    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    $summary = null;

    if (is_file($summaryPath)) {
        $decoded = json_decode((string) file_get_contents($summaryPath), true);
        $summary = is_array($decoded) ? $decoded : null;
        @unlink($summaryPath);
    }

    return ['exit' => $exit, 'output' => implode("\n", $output), 'summary' => $summary];
}

/**
 * The assertion harness every control shares.
 *
 * A control passes only when ALL of the following hold:
 *
 *   1. the validator actually ran   — a parsed JSON summary exists;
 *   2. the SPECIFIC expected check failed — the needle appears in the output;
 *   3. the exit code is the governance-failure code (1), not 2 or 3;
 *   4. the summary attributes the outcome to a real failure — `failed >= 1`
 *      and `exit_reason == governance_failure`, so an EXTERNAL_UNVERIFIED item
 *      can never be what made the control "succeed";
 *   5. the mutation was confined to the fixture.
 *
 * @param callable(string):void $mutate receives the fixture root
 */
function m29_control(string $name, callable $mutate, string $expectNeedle): void
{
    global $passed, $falseNegatives;

    $fixture = m29_make_fixture();

    try {
        // A fixture that is already failing would make any mutation look
        // effective. Prove it starts clean before breaking it.
        $baseline = m29_run_validator($fixture);

        if ($baseline['exit'] !== 0) {
            $falseNegatives++;
            printf("  ✘ %s — the PRISTINE fixture already failed (exit=%d); the control proves nothing\n", $name, $baseline['exit']);

            return;
        }

        $mutate($fixture);
        m29_assert_contained($fixture);

        $result = m29_run_validator($fixture);
        $summary = $result['summary'];

        if ($summary === null) {
            $falseNegatives++;
            printf("  ✘ %s — the validator produced no JSON summary; it may not have run\n", $name);

            return;
        }

        $ranSomething = ($summary['passed'] ?? 0) + ($summary['failed'] ?? 0) > 0;
        $attributedToFailure = ($summary['failed'] ?? 0) >= 1
            && ($summary['exit_reason'] ?? '') === 'governance_failure';
        $exitOk = $result['exit'] === 1;

        // The needle must appear on a FAIL line, not merely somewhere in the
        // output. Matching the whole transcript is how a control convinces
        // itself it worked: an early version of this harness accepted
        // "contexts agree" from the PASS line that says the contexts DO agree,
        // while the run had gone non-zero for an entirely unrelated reason.
        // A control that fires for the wrong reason is a false negative.
        $failLines = array_filter(
            explode("\n", $result['output']),
            static fn (string $l): bool => str_starts_with(trim($l), 'FAIL '),
        );
        $needleFound = false;

        foreach ($failLines as $line) {
            if (str_contains($line, $expectNeedle)) {
                $needleFound = true;

                break;
            }
        }

        if ($ranSomething && $attributedToFailure && $needleFound && $exitOk) {
            $passed++;
            printf("  ✔ %s\n", $name);

            foreach (array_slice(array_values(array_filter(
                $failLines,
                static fn (string $l): bool => str_contains($l, $expectNeedle),
            )), 0, 1) as $line) {
                printf("      %s\n", trim($line));
            }

            return;
        }

        $falseNegatives++;
        printf(
            "  ✘ %s — FALSE NEGATIVE (exit=%d, failed=%s, reason=%s, needle=%s)\n",
            $name,
            $result['exit'],
            (string) ($summary['failed'] ?? '?'),
            (string) ($summary['exit_reason'] ?? '?'),
            $needleFound ? 'found' : "MISSING \"{$expectNeedle}\"",
        );
    } catch (Throwable $e) {
        $falseNegatives++;
        printf("  ✘ %s — control raised %s: %s\n", $name, $e::class, $e->getMessage());
    } finally {
        // Only a fixture is being cleaned up here. Nothing in the real
        // repository depends on this running.
        m29_rmtree($fixture);
    }
}

/** An untouched fixture must pass — the control on the controls. */
function m29_positive_control(): void
{
    global $passed, $falseNegatives;

    $fixture = m29_make_fixture();

    try {
        m29_assert_contained($fixture);
        $result = m29_run_validator($fixture);

        if ($result['exit'] === 0 && ($result['summary']['failed'] ?? 1) === 0) {
            $passed++;
            echo "  ✔ positive control · an untouched fixture passes\n";

            return;
        }

        $falseNegatives++;
        printf("  ✘ positive control · an untouched fixture FAILED (exit=%d) — every control above proves nothing\n", $result['exit']);
    } finally {
        m29_rmtree($fixture);
    }
}

/**
 * A fixture missing a file the validator needs must fail loudly.
 *
 * Without this, an incomplete fixture could quietly turn real checks into
 * PASS-by-absence and the whole suite would be measuring nothing.
 */
function m29_completeness_control(string $omit, string $expectNeedle): void
{
    global $passed, $falseNegatives;

    $fixture = m29_make_fixture([$omit]);

    try {
        $result = m29_run_validator($fixture);
        $summary = $result['summary'];

        $loud = $result['exit'] === 1
            && ($summary['failed'] ?? 0) >= 1
            && str_contains($result['output'], $expectNeedle);

        if ($loud) {
            $passed++;
            printf("  ✔ completeness control · a fixture without %s fails loudly\n", $omit);

            return;
        }

        $falseNegatives++;
        printf(
            "  ✘ completeness control · a fixture without %s did NOT fail loudly (exit=%d)\n",
            $omit,
            $result['exit'],
        );
    } finally {
        m29_rmtree($fixture);
    }
}

function m29_identities_validator(): string
{
    return __DIR__.'/verify_governance_identities.php';
}

/**
 * Run the identity resolver entirely against fixture paths.
 *
 * It already accepts --identities, --codeowners and --tags, so no root
 * parameter is needed: every path it would otherwise derive from the real
 * repository can be pointed at the fixture explicitly.
 *
 * @param list<string> $extraArgs
 *
 * @return array{exit:int, output:string}
 *
 * @phpstan-param list<string> $extraArgs
 */
function m29_run_identities(string $fixture, array $extraArgs = []): array
{
    $command = 'php '.escapeshellarg(m29_identities_validator())
        .' --identities='.escapeshellarg($fixture.'/.github/governance/identities.json')
        .' --codeowners='.escapeshellarg($fixture.'/.github/CODEOWNERS')
        .' --tags='.escapeshellarg($fixture.'/.github/governance/production-tags-ruleset.json');

    foreach ($extraArgs as $arg) {
        $command .= ' '.$arg;
    }

    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

/**
 * An identity control: mutate the fixture, run the resolver against it, and
 * require a SPECIFIC finding code.
 *
 * Finding codes (IDENTITY_ROLE_MISSING and friends) are the assertion rather
 * than the exit status, because the resolver exits non-zero for several
 * reasons and "it failed" would not say which.
 *
 * @param callable(string):void $mutate
 * @param list<string> $forbid substrings that must NOT appear
 * @param list<string> $extraArgs extra CLI arguments, already escaped
 */
function m29_identity_control(
    string $name,
    callable $mutate,
    string $expectNeedle,
    bool $expectFailure = true,
    array $forbid = [],
    array $extraArgs = [],
): void {
    global $passed, $falseNegatives;

    $fixture = m29_make_fixture();

    try {
        $mutate($fixture);
        m29_assert_contained($fixture);

        $result = m29_run_identities($fixture, $extraArgs);

        $exitOk = $expectFailure ? $result['exit'] !== 0 : $result['exit'] === 0;
        $needleOk = str_contains($result['output'], $expectNeedle);
        $forbidOk = true;

        foreach ($forbid as $needle) {
            if (str_contains($result['output'], $needle)) {
                $forbidOk = false;
            }
        }

        if ($exitOk && $needleOk && $forbidOk) {
            $passed++;
            printf("  ✔ %s\n", $name);
            printf("      %s\n", $expectNeedle);

            return;
        }

        $falseNegatives++;
        printf(
            "  ✘ %s — FALSE NEGATIVE (exit=%d, expected %s%s%s)\n",
            $name,
            $result['exit'],
            $expectFailure ? 'failure' : 'success',
            $needleOk ? '' : "; missing \"{$expectNeedle}\"",
            $forbidOk ? '' : '; forbidden text present',
        );
    } catch (Throwable $e) {
        $falseNegatives++;
        printf("  ✘ %s — control raised %s: %s\n", $name, $e::class, $e->getMessage());
    } finally {
        m29_rmtree($fixture);
    }
}

/**
 * Write a JSON document into a fixture.
 *
 * @param array<string, mixed> $doc
 */
function m29_write_json(string $path, array $doc): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, true);
    }

    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Read a JSON document from a fixture.
 *
 * @return array<string, mixed>
 */
function m29_read_json(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
