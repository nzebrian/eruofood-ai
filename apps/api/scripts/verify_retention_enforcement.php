<?php

declare(strict_types=1);

/**
 * M42 — is declared retention actually enforced, and is enforcement actually off?
 *
 * ## The two claims this holds apart
 *
 * `RetentionRegistry` says the platform keeps idempotency claims for one day and
 * rider positions for thirty. Until M42 nothing deleted either, so those numbers
 * were a claim rather than a control. M42 makes them enforceable — which
 * immediately creates the opposite risk, because every enforcement path here is
 * an irreversible delete (`DeletionMode::isReversible()` is true for exactly one
 * mode, and it is not `Destroy`).
 *
 * So this validator asserts both halves at once:
 *
 *   ENFORCEABLE — every non-indefinite policy has a command or a written reason.
 *   OFF         — no schedule is enabled, and the master flag defaults false.
 *
 * A change that satisfies one by breaking the other must fail here, and the
 * negative controls in `m42_retention_negative_control.php` prove that it does.
 *
 * ## Why this reads source text rather than booting Laravel
 *
 * Deliberate, and the same choice `verify_repository_governance.php` makes. The
 * controls run this against a disposable fixture — a temporary directory holding
 * copies of exactly the files below — so the mutation under test must be visible
 * in the bytes on disk. Booting the framework would resolve the real classes
 * through the real autoloader and quietly test the unmutated repository, which
 * is the failure mode that makes a control useless while looking green.
 *
 * Behaviour is proved separately, by `RetentionEnforcementTest` and
 * `RiderLocationRetentionTest`, which do run the real commands against a real
 * database. This script proves the invariants those tests cannot see: that no
 * schedule is enabled, that nothing bypasses the gate, that no policy has been
 * left without an enforcement path.
 *
 * ## Running it
 *
 *   php scripts/verify_retention_enforcement.php
 *   php scripts/verify_retention_enforcement.php --repo-root=<fixture> --json=<path>
 *
 * Exit 0 when every check passes, 1 when a retention invariant is violated,
 * 3 when the validator could not run or was misinvoked.
 */

// Exit codes, matching verify_repository_governance.php. A misinvocation must
// not be reportable as a governance failure, nor a failure as a bad argument.
const EXIT_OK = 0;
const EXIT_FAIL = 1;
const EXIT_ERROR = 3;

const SUMMARY_SCHEMA = 1;

/**
 * The number of checks this validator is expected to run.
 *
 * House convention, and it earns its keep: without it a refactor that silently
 * stops registering half the checks reports "all checks passed" and means
 * nothing. The count is asserted at the end and a mismatch is a failure.
 */
const EXPECTED_CHECKS = 22;

// Stable check identifiers. The negative controls name these, so each control
// can assert that the SPECIFIC check it targeted failed — rather than settling
// for a non-zero exit, which any unrelated breakage would also produce.
const CHECK_IDEMPOTENCY_USES_EXPIRES_AT = 'retention.idempotency_uses_expires_at';
const CHECK_IDEMPOTENCY_NOT_CREATED_AT = 'retention.idempotency_not_created_at';
const CHECK_IDEMPOTENCY_NO_DAYS_OPTION = 'retention.idempotency_no_days_option';
const CHECK_DRY_RUN_OPTION_PRESENT = 'retention.dry_run_option_present';
const CHECK_DRY_RUN_IS_NON_DESTRUCTIVE = 'retention.dry_run_is_non_destructive';
const CHECK_WINDOW_MUST_BE_POSITIVE = 'retention.window_must_be_positive';
const CHECK_CHUNK_MUST_BE_POSITIVE = 'retention.chunk_must_be_positive';
const CHECK_DELETION_IS_CHUNKED = 'retention.deletion_is_chunked';
const CHECK_NO_UNBOUNDED_DELETE = 'retention.no_unbounded_delete';
const CHECK_ANONYMISE_STAYS_ANONYMISE = 'retention.anonymise_stays_anonymise';
const CHECK_COVERAGE_COMPLETE = 'retention.coverage_complete';
const CHECK_COVERAGE_FAILS_CLOSED = 'retention.coverage_fails_closed';
const CHECK_EXEMPTIONS_ARE_REASONED = 'retention.exemptions_are_reasoned';
const CHECK_GATE_APPLIED_IN_BOOTSTRAP = 'retention.gate_applied_in_bootstrap';
const CHECK_GATE_READS_MASTER_FLAG = 'retention.gate_reads_master_flag';
const CHECK_MASTER_FLAG_SAFE_DEFAULT = 'retention.master_flag_safe_default';
const CHECK_MASTER_FLAG_NOT_FORCED_ON = 'retention.master_flag_not_forced_on';
const CHECK_SCHEDULES_DISABLED = 'retention.schedules_disabled';
const CHECK_SCHEDULES_MARKED_DESTRUCTIVE = 'retention.schedules_marked_destructive';
const CHECK_NO_DUPLICATE_REGISTRATION = 'retention.no_duplicate_registration';
const CHECK_OUTPUT_HAS_NO_SENSITIVE_FIELDS = 'retention.output_has_no_sensitive_fields';
const CHECK_CHECK_COUNT = 'retention.check_count';

// Every file this validator reads, repo-relative. Defined once and shared with
// the negative controls, which copy exactly this list into their fixtures — see
// the docblock there for why two hand-maintained copies would be a trap.
require_once __DIR__.'/verify_retention_enforcement_sources.php';

/** The commands whose shared safety properties are asserted together. */
const PURGE_COMMANDS = ['idempotency_command', 'rider_command', 'search_command'];

/** Commands that take a caller-supplied window. The idempotency purge deliberately does not. */
const WINDOWED_COMMANDS = ['rider_command', 'search_command'];

$repoRoot = dirname(__DIR__, 3);
$jsonPath = null;

foreach (array_slice($argv, 1) as $arg) {
    $arg = (string) $arg;

    if (str_starts_with($arg, '--repo-root=')) {
        $candidate = substr($arg, strlen('--repo-root='));
        $resolved = $candidate === '' ? false : realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            fwrite(STDERR, "ERROR  --repo-root is not a readable directory: {$candidate}\n");
            exit(EXIT_ERROR);
        }

        $repoRoot = $resolved;

        continue;
    }

    if (str_starts_with($arg, '--json=')) {
        $jsonPath = substr($arg, strlen('--json='));

        if ($jsonPath === '') {
            fwrite(STDERR, "ERROR  --json= was given an empty path\n");
            exit(EXIT_ERROR);
        }

        continue;
    }

    fwrite(STDERR, "ERROR  unknown argument: {$arg}\n");
    fwrite(STDERR, "usage: verify_retention_enforcement.php [--repo-root=<path>] [--json=<path>]\n");
    exit(EXIT_ERROR);
}

$passed = 0;
$failed = 0;

/** @var list<array{id: string, check: string, detail: string}> $failures */
$failures = [];

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** @param callable():array{bool, string} $check */
function verify(string $id, string $description, callable $check): void
{
    global $passed, $failed, $failures;

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        // A validator that throws must report a failure, not vanish with a
        // stack trace that a CI log truncates.
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    if ($ok) {
        $passed++;
    } else {
        $failed++;
        $failures[] = ['id' => $id, 'check' => $description, 'detail' => $detail];
    }

    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $description, $detail === '' ? '' : "  ({$detail})");
}

/**
 * Read one of the sources, or fail the run.
 *
 * A missing source is EXIT_ERROR rather than EXIT_FAIL: "the file is not there"
 * is not evidence that retention is unsafe, and reporting it as a retention
 * failure would let a broken fixture masquerade as a real finding.
 */
function source(string $key): string
{
    global $repoRoot;

    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $path = $repoRoot.'/'.m42_retention_sources()[$key];
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        fwrite(STDERR, "ERROR  cannot read required source: {$path}\n");
        exit(EXIT_ERROR);
    }

    return $cache[$key] = $contents;
}

/** The body of one method, from its signature to the next one-tab closing brace. */
function methodBody(string $php, string $signatureFragment): string
{
    $start = strpos($php, $signatureFragment);

    if ($start === false) {
        return '';
    }

    $end = strpos($php, "\n    }", $start);

    return $end === false ? substr($php, $start) : substr($php, $start, $end - $start);
}

/** Strip block and line comments, so a check cannot be satisfied by prose about it. */
function code(string $php): string
{
    $stripped = preg_replace('~/\*.*?\*/~s', '', $php) ?? $php;

    return preg_replace('~//[^\n]*~', '', $stripped) ?? $stripped;
}

echo "EruoFood — M42 retention enforcement verification\n";
echo str_repeat('=', 78)."\n";
echo "Repository root: {$repoRoot}\n";

// =============================================================================
section('1. Idempotency purge — eligibility is expiry, never age');
// =============================================================================

// The single most consequential property in M42. `expires_at` is what the store
// consults to decide whether a claim may still be replayed; a claim is safe to
// delete exactly when the store has stopped honouring it. Switching the
// predicate to `created_at` would let an operator delete a LIVE claim, and a
// deleted live claim reopens the duplicate-payment window that claim exists to
// close — the retry it would have collapsed executes a second time.

verify(CHECK_IDEMPOTENCY_USES_EXPIRES_AT, 'the idempotency purge selects on expires_at', function (): array {
    $body = code(methodBody(source('idempotency_store'), 'public function purgeExpired('));

    if ($body === '') {
        return [false, 'purgeExpired() not found in the store'];
    }

    return [
        str_contains($body, "'expires_at'"),
        str_contains($body, "'expires_at'") ? '' : 'purgeExpired() does not reference expires_at',
    ];
});

verify(CHECK_IDEMPOTENCY_NOT_CREATED_AT, 'the idempotency purge never selects on created_at', function (): array {
    $store = code(source('idempotency_store'));

    foreach (['countExpired(', 'purgeExpired('] as $method) {
        $body = methodBody($store, 'public function '.$method);

        if ($body === '') {
            return [false, "{$method}) not found in the store"];
        }

        if (str_contains($body, "'created_at'")) {
            return [false, "{$method}) references created_at, which is age and not eligibility"];
        }
    }

    return [true, ''];
});

verify(CHECK_IDEMPOTENCY_NO_DAYS_OPTION, 'the idempotency command exposes no --days window', function (): array {
    // Its absence is the control. A `--days` flag over `created_at` is exactly
    // the mistake the check above forbids, offered to an operator as an option.
    $php = source('idempotency_command');
    $signature = methodBody($php, 'protected $signature');

    return [
        ! str_contains($signature, '--days'),
        str_contains($signature, '--days') ? 'the idempotency purge must not take a caller-supplied age window' : '',
    ];
});

// =============================================================================
section('2. Dry run is genuinely a dry run');
// =============================================================================

verify(CHECK_DRY_RUN_OPTION_PRESENT, 'every purge command offers --dry-run', function (): array {
    $missing = [];

    foreach (PURGE_COMMANDS as $key) {
        if (! str_contains(source($key), '--dry-run')) {
            $missing[] = m42_retention_sources()[$key];
        }
    }

    return [$missing === [], $missing === [] ? '' : 'missing --dry-run: '.implode(', ', $missing)];
});

verify(CHECK_DRY_RUN_IS_NON_DESTRUCTIVE, 'a dry run returns before it can delete anything', function (): array {
    // Not "it has a dry-run branch" — that survives a branch that reports and
    // then falls through into the delete. The branch must RETURN, and the
    // destructive call must sit after it.
    $destructive = [
        'idempotency_command' => 'purgeExpired(',
        'rider_command' => 'purgeRecordedBefore(',
        'search_command' => 'purge',
    ];

    foreach (PURGE_COMMANDS as $key) {
        $handle = code(methodBody(source($key), 'public function handle('));

        if ($handle === '') {
            return [false, m42_retention_sources()[$key].': handle() not found'];
        }

        $branch = strpos($handle, "option('dry-run')");

        if ($branch === false) {
            return [false, m42_retention_sources()[$key].': handle() never consults --dry-run'];
        }

        // Everything from the dry-run branch to the end of its block.
        $tail = substr($handle, $branch);
        $returnAt = strpos($tail, 'return');

        if ($returnAt === false) {
            return [false, m42_retention_sources()[$key].': the dry-run branch does not return'];
        }

        $inBranch = substr($tail, 0, $returnAt);

        if (str_contains($inBranch, $destructive[$key])) {
            return [false, m42_retention_sources()[$key].': the dry-run branch reaches a destructive call'];
        }
    }

    return [true, ''];
});

// =============================================================================
section('3. Windows and chunks must be positive');
// =============================================================================

verify(CHECK_WINDOW_MUST_BE_POSITIVE, 'a non-positive retention window is refused, not applied', function (): array {
    // `--days=0` puts the cutoff at `now` and makes every row eligible,
    // including the one written a second ago. Misconfiguration must fail loudly
    // rather than quietly empty a table.
    foreach (WINDOWED_COMMANDS as $key) {
        $handle = code(methodBody(source($key), 'public function handle('));

        if (! preg_match('~\$days\s*<=\s*0~', $handle)) {
            return [false, m42_retention_sources()[$key].': no `$days <= 0` guard in handle()'];
        }

        if (! preg_match('~\$days\s*<=\s*0.*?return\s+self::FAILURE~s', $handle)) {
            return [false, m42_retention_sources()[$key].': the non-positive window guard does not fail the command'];
        }
    }

    return [true, ''];
});

verify(CHECK_CHUNK_MUST_BE_POSITIVE, 'a non-positive chunk is refused by every purge path', function (): array {
    foreach (PURGE_COMMANDS as $key) {
        $handle = code(methodBody(source($key), 'public function handle('));

        if (! preg_match('~\$chunk\s*<=\s*0.*?return\s+self::FAILURE~s', $handle)) {
            return [false, m42_retention_sources()[$key].': no failing `$chunk <= 0` guard in handle()'];
        }
    }

    // And in the adapters, so a caller reaching them directly cannot pass zero
    // and spin forever on an empty batch.
    foreach (['idempotency_store' => 'purgeExpired(', 'rider_repository' => 'purgeRecordedBefore('] as $key => $method) {
        $body = code(methodBody(source($key), 'public function '.$method));

        if (! preg_match('~chunkSize\s*<=\s*0.*?throw~s', $body)) {
            return [false, m42_retention_sources()[$key].": {$method}) does not reject a non-positive chunk size"];
        }
    }

    return [true, ''];
});

// =============================================================================
section('4. Deletion is chunked and interruptible');
// =============================================================================

verify(CHECK_DELETION_IS_CHUNKED, 'deletion selects a bounded batch of ids and deletes by id', function (): array {
    // PostgreSQL has no `DELETE … LIMIT`, so a bounded delete has to be two
    // statements. A first purge over a large backlog must be a series of small
    // interruptible deletes rather than one lock-holding transaction.
    foreach (['idempotency_store' => 'purgeExpired(', 'rider_repository' => 'purgeRecordedBefore('] as $key => $method) {
        $body = code(methodBody(source($key), 'public function '.$method));

        if ($body === '') {
            return [false, m42_retention_sources()[$key].": {$method}) not found"];
        }

        foreach (['limit($chunkSize)' => 'no bounded batch', 'whereIn(' => 'does not delete by id', 'pluck(' => 'does not collect ids'] as $needle => $why) {
            if (! str_contains($body, $needle)) {
                return [false, m42_retention_sources()[$key].": {$method}) {$why}"];
            }
        }
    }

    return [true, ''];
});

verify(CHECK_NO_UNBOUNDED_DELETE, 'no purge path issues an unbounded delete', function (): array {
    // A `->delete()` whose builder carries neither a `whereIn` on collected ids
    // nor a limit is the shape this forbids: one statement, one lock, the whole
    // backlog, no way to stop it.
    foreach (['idempotency_store' => 'purgeExpired(', 'rider_repository' => 'purgeRecordedBefore('] as $key => $method) {
        $body = code(methodBody(source($key), 'public function '.$method));

        foreach (explode("\n", $body) as $line) {
            if (! str_contains($line, '->delete()')) {
                continue;
            }

            if (! str_contains($line, 'whereIn(')) {
                return [false, m42_retention_sources()[$key].": {$method}) deletes without an id batch: ".trim($line)];
            }
        }
    }

    return [true, ''];
});

// =============================================================================
section('5. Policy modes are respected, and coverage is complete');
// =============================================================================

/**
 * Policy keys declared in the registry, read from source.
 *
 * @return list<string>
 */
function declaredPolicyKeys(): array
{
    preg_match_all("~key:\s*'([a-z0-9_.]+)'~i", source('registry'), $matches);

    return array_values(array_unique($matches[1] ?? []));
}

/** @return array{commands: list<string>, exempt: list<string>} */
function enforcementMap(): array
{
    $php = source('enforcement');

    $extract = static function (string $constant) use ($php): array {
        $start = strpos($php, 'const array '.$constant.' = [');

        if ($start === false) {
            return [];
        }

        $end = strpos($php, "\n    ];", $start);
        $block = $end === false ? substr($php, $start) : substr($php, $start, $end - $start);

        preg_match_all("~'([a-z0-9_.]+)'\s*=>~i", $block, $m);

        return array_values($m[1] ?? []);
    };

    return ['commands' => $extract('COMMANDS'), 'exempt' => $extract('EXEMPT')];
}

verify(CHECK_ANONYMISE_STAYS_ANONYMISE, 'an Anonymise policy is not quietly converted to Destroy', function (): array {
    // notifications.sent is exempt from automated enforcement precisely BECAUSE
    // its mode is Anonymise and no anonymisation mechanism exists to reuse.
    // "Fixing" that gap by flipping the mode would delete the record instead of
    // depersonalising it — the purpose ("show somebody what we sent them")
    // survives anonymisation and does not survive deletion.
    $php = source('registry');
    $start = strpos($php, "key: 'notifications.sent'");

    if ($start === false) {
        return [false, 'notifications.sent is no longer declared'];
    }

    $block = substr($php, $start, 900);

    if (! str_contains($block, 'DeletionMode::Anonymise')) {
        return [false, 'notifications.sent no longer declares DeletionMode::Anonymise'];
    }

    $map = enforcementMap();

    if (in_array('notifications.sent', $map['commands'], true)) {
        return [false, 'notifications.sent has a destructive enforcement command while its mode is Anonymise'];
    }

    return [true, ''];
});

verify(CHECK_COVERAGE_COMPLETE, 'every declared policy has an enforcement path or a documented exemption', function (): array {
    $map = enforcementMap();
    $covered = array_merge($map['commands'], $map['exempt']);
    $uncovered = array_values(array_diff(declaredPolicyKeys(), $covered));

    return [
        $uncovered === [],
        $uncovered === [] ? '' : 'uncovered: '.implode(', ', $uncovered),
    ];
});

verify(CHECK_COVERAGE_FAILS_CLOSED, 'an unknown policy key throws rather than defaulting to unenforced', function (): array {
    // Without this, a policy added next year returns null from `for()`, reads as
    // "exempt", and is silently unenforced. The safe direction for an unknown
    // key is a loud failure.
    $body = code(methodBody(source('enforcement'), 'public static function for('));

    if (! str_contains($body, 'throw new InvalidArgumentException')) {
        return [false, 'RetentionEnforcement::for() does not throw on an unknown key'];
    }

    // Presence of the throw is not enough. `return null;` inserted above it
    // leaves the throw in the file, unreachable, and an existence check still
    // passes — so the property asserted is that the method has no UNGUARDED
    // exit other than the throw. Every legitimate return in `for()` sits inside
    // an `array_key_exists` branch and is indented one level deeper; a statement
    // at the method's own indentation is a fall-through.
    foreach (explode("\n", $body) as $line) {
        if (preg_match('~^        return\b~', $line)) {
            return [false, 'for() has an unguarded `'.trim($line).'`, so an unknown key falls through as exempt'];
        }
    }

    return [true, ''];
});

verify(CHECK_EXEMPTIONS_ARE_REASONED, 'every exemption carries a substantive written reason', function (): array {
    // An exemption map whose values are empty strings satisfies the coverage
    // check while documenting nothing.
    $php = source('enforcement');
    $start = strpos($php, 'const array EXEMPT = [');

    if ($start === false) {
        return [false, 'the EXEMPT map is missing'];
    }

    $end = strpos($php, "\n    ];", $start);
    $block = $end === false ? substr($php, $start) : substr($php, $start, $end - $start);

    // Split on the key boundaries so each reason is measured against its OWN
    // text. Reading a fixed window forward from the key would let an empty
    // reason borrow the length of the entry after it.
    $parts = preg_split("~'([a-z0-9_.]+)'\s*=>~i", $block, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

    for ($i = 1; $i < count($parts); $i += 2) {
        $key = $parts[$i];
        $reason = trim($parts[$i + 1] ?? '');

        if (strlen($reason) < 120) {
            return [false, "exemption '{$key}' has no substantive reason"];
        }
    }

    return [true, ''];
});

// =============================================================================
section('6. The master flag actually gates the scheduler');
// =============================================================================

verify(CHECK_GATE_APPLIED_IN_BOOTSTRAP, 'the scheduler skips destructive retention tasks when the gate is closed', function (): array {
    $php = code(source('bootstrap'));

    if (! str_contains($php, 'RetentionGate')) {
        return [false, 'bootstrap/app.php does not consult the retention gate'];
    }

    if (! preg_match('~destructiveRetention\s*&&\s*!\s*\$\w+->allowsScheduledPurge\(\)~', $php)) {
        return [false, 'no `destructiveRetention && ! allowsScheduledPurge()` guard in the schedule loop'];
    }

    // The guard must skip the task, not merely be evaluated.
    if (! preg_match('~allowsScheduledPurge\(\)\s*\)\s*\{\s*continue;~s', $php)) {
        return [false, 'the gate guard does not skip the task'];
    }

    return [true, ''];
});

verify(CHECK_GATE_READS_MASTER_FLAG, 'the gate reads lifecycle.retention_purge and nothing else', function (): array {
    $php = source('gate');

    if (! str_contains($php, "'lifecycle.retention_purge'")) {
        return [false, 'RetentionGate does not name lifecycle.retention_purge'];
    }

    $body = code(methodBody($php, 'public function allowsScheduledPurge('));

    if (! str_contains($body, 'isEnabled(self::FLAG)')) {
        return [false, 'allowsScheduledPurge() does not evaluate the flag'];
    }

    // A second competing master flag was explicitly out of scope; a hard-coded
    // `return true` is the same defect in cheaper clothing.
    if (preg_match('~return\s+true\s*;~', $body)) {
        return [false, 'allowsScheduledPurge() short-circuits to true'];
    }

    return [true, ''];
});

verify(CHECK_MASTER_FLAG_SAFE_DEFAULT, 'lifecycle.retention_purge declares safeDefault: false', function (): array {
    $php = source('shared_provider');
    $at = strpos($php, "key: 'lifecycle.retention_purge'");

    if ($at === false) {
        return [false, 'the lifecycle.retention_purge flag is not declared'];
    }

    $block = substr($php, $at, 300);

    return [
        (bool) preg_match('~safeDefault:\s*false~', $block),
        preg_match('~safeDefault:\s*false~', $block) ? '' : 'the flag does not declare safeDefault: false',
    ];
});

verify(CHECK_MASTER_FLAG_NOT_FORCED_ON, 'no configuration turns the flag on by default', function (): array {
    $php = source('flags_config');
    $at = strpos($php, "'lifecycle.retention_purge'");

    if ($at === false) {
        return [false, 'the flag is absent from config/flags.php'];
    }

    $line = substr($php, $at, 200);
    $line = substr($line, 0, (int) strpos($line."\n", "\n"));

    // `env(...)` with no default resolves to null, and the evaluator falls back
    // to the safe default. A literal true, or an env() default of true, would
    // ship it on.
    if (preg_match('~=>\s*true~', $line) || preg_match('~env\([^)]*,\s*true\s*\)~', $line)) {
        return [false, 'config/flags.php defaults lifecycle.retention_purge to on: '.trim($line)];
    }

    return [true, ''];
});

// =============================================================================
section('7. Every retention schedule ships disabled');
// =============================================================================

/**
 * Every ScheduledTask::of(...) block in the providers, keyed by task name.
 *
 * @return array<string, string>
 */
function scheduledTaskBlocks(): array
{
    $blocks = [];

    foreach (['shared_provider', 'geo_provider', 'search_provider'] as $key) {
        $php = source($key);
        $offset = 0;

        while (($at = strpos($php, 'ScheduledTask::of(', $offset)) !== false) {
            $end = strpos($php, '));', $at);
            $block = $end === false ? substr($php, $at) : substr($php, $at, $end - $at);
            $offset = $at + 1;

            if (! preg_match("~name:\s*'([^']+)'~", $block, $m)) {
                continue;
            }

            // Same task name registered twice is a duplicate registration, which
            // the next check reports; keep both by suffixing.
            $name = $m[1];
            $blocks[isset($blocks[$name]) ? $name.'#dup' : $name] = $block;
        }
    }

    return $blocks;
}

verify(CHECK_SCHEDULES_DISABLED, 'no destructive retention task is registered enabled', function (): array {
    $enabled = [];

    foreach (scheduledTaskBlocks() as $name => $block) {
        if (! preg_match('~destructiveRetention:\s*true~', $block)) {
            continue;
        }

        if (! preg_match('~enabled:\s*false~', $block)) {
            $enabled[] = $name;
        }
    }

    return [
        $enabled === [],
        $enabled === [] ? '' : 'enabled retention schedules: '.implode(', ', $enabled),
    ];
});

verify(CHECK_SCHEDULES_MARKED_DESTRUCTIVE, 'every purge/anonymise task is marked destructiveRetention', function (): array {
    // The marking is what subjects a task to the gate. An unmarked purge task is
    // registered, disabled, and one `enabled: true` away from running unattended
    // with no second lock behind it.
    $unmarked = [];

    foreach (scheduledTaskBlocks() as $name => $block) {
        if (! preg_match('~purge|anonymise~i', $name)) {
            continue;
        }

        if (! preg_match('~destructiveRetention:\s*true~', $block)) {
            $unmarked[] = $name;
        }
    }

    return [$unmarked === [], $unmarked === [] ? '' : 'unmarked: '.implode(', ', $unmarked)];
});

verify(CHECK_NO_DUPLICATE_REGISTRATION, 'no retention task is registered twice', function (): array {
    // ScheduleRegistry::register() would throw at boot, which is a worse place
    // to find out.
    $duplicates = array_values(array_filter(
        array_keys(scheduledTaskBlocks()),
        static fn (string $name): bool => str_ends_with($name, '#dup'),
    ));

    return [$duplicates === [], $duplicates === [] ? '' : 'duplicated: '.implode(', ', $duplicates)];
});

// =============================================================================
section('8. Commands print counts, not contents');
// =============================================================================

verify(CHECK_OUTPUT_HAS_NO_SENSITIVE_FIELDS, 'no purge command interpolates a sensitive value into its output', function (): array {
    // These commands exist because the values they delete should not persist.
    // Reading them out to an operator's terminal on the way to deleting them
    // would defeat the purpose and, worse, copy them into CI logs.
    $forbidden = [
        'idempotency_key', 'request_hash', 'response_snapshot', 'user_id',
        'latitude', 'longitude', 'rider_id', 'query_text', 'search_term',
    ];

    foreach (PURGE_COMMANDS as $key) {
        $handle = code(methodBody(source($key), 'public function handle('));

        foreach (explode("\n", $handle) as $line) {
            if (! preg_match('~\$this->(info|line|warn|error|comment|table)\(~', $line)) {
                continue;
            }

            foreach ($forbidden as $field) {
                if (str_contains($line, $field)) {
                    return [false, m42_retention_sources()[$key].": output mentions {$field}: ".trim($line)];
                }
            }
        }
    }

    return [true, ''];
});

// =============================================================================
section('9. The validator ran the checks it claims to run');
// =============================================================================

verify(CHECK_CHECK_COUNT, sprintf('exactly %d checks were evaluated', EXPECTED_CHECKS), function (): array {
    global $passed, $failed;

    // +1 for this check itself, which has not been counted yet.
    $total = $passed + $failed + 1;

    return [
        $total === EXPECTED_CHECKS,
        $total === EXPECTED_CHECKS ? '' : "evaluated {$total}, expected ".EXPECTED_CHECKS,
    ];
});

echo "\n".str_repeat('=', 78)."\n";
printf("%d passed, %d failed\n", $passed, $failed);

if ($failures !== []) {
    echo "\nFAILURES\n";
    foreach ($failures as $failure) {
        printf("  [%s] %s\n      %s\n", $failure['id'], $failure['check'], $failure['detail']);
    }
}

$exitCode = $failed === 0 ? EXIT_OK : EXIT_FAIL;

if ($jsonPath !== null) {
    $summary = [
        'schema' => SUMMARY_SCHEMA,
        'generated_at' => gmdate('c'),
        'repo_root' => $repoRoot,
        'passed' => $passed,
        'failed' => $failed,
        'expected_checks' => EXPECTED_CHECKS,
        'exit_code' => $exitCode,
        'failures' => $failures,
    ];

    if (file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
        fwrite(STDERR, "ERROR  could not write --json summary to {$jsonPath}\n");
        exit(EXIT_ERROR);
    }
}

echo $exitCode === EXIT_OK
    ? "\nRetention enforcement verified: every policy covered, every schedule disabled.\n"
    : "\nRetention enforcement FAILED.\n";

exit($exitCode);
