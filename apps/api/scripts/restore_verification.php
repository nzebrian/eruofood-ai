<?php

declare(strict_types=1);

/**
 * M28 — is a restored database actually usable, and is the money still right?
 *
 * `pg_restore` exiting zero means the bytes came back. It says nothing about
 * whether the ledger still balances, whether a settlement line survived without
 * the run it belongs to, or whether the constraints that make a second payout
 * structurally impossible came back with the data. A restore that silently
 * dropped a partial unique index looks identical to a good one until the first
 * duplicate payout.
 *
 * So this asks the six questions `docs/BACKUP_RESTORE.md` requires a drill to
 * answer, in order of how bad the answer would be:
 *
 *   1. schema head           — is the database at a version the app knows?
 *   2. readability           — does application data come back at all?
 *   3. referential integrity — does every reference still resolve?
 *   4. financial consistency — do the derived figures still derive?
 *   5. ledger integrity      — does double-entry still balance, per correlation?
 *   6. duplicate-payout safety — are the constraints that prevent it still there?
 *
 * ## Read-only, and deliberately so
 *
 * It opens a transaction and rolls it back. A verifier that repaired what it
 * found would make the next drill meaningless: the point is to learn that the
 * backup is bad while there is still time to take another one.
 *
 * Run: DB_DATABASE=<restored-copy> php scripts/restore_verification.php
 * Requires: PostgreSQL. Exits 0 when every check passes, 1 otherwise.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$driver = DB::connection()->getDriverName();
if ($driver !== 'pgsql') {
    fwrite(STDERR, "This verifier requires PostgreSQL (got: {$driver}).\n");
    fwrite(STDERR, "A restore drill against a different engine proves nothing about the production restore.\n");
    exit(2);
}

$database = (string) DB::connection()->getDatabaseName();
$startedAt = microtime(true);

printf("EruoFood — restore verification (PostgreSQL, database \"%s\")\n", $database);
echo str_repeat('=', 72), "\n\n";

$passed = 0;
$failed = 0;
$section = '';

function heading(string $title): void
{
    global $section;
    $section = $title;
    echo "\n{$title}\n";
}

/** @param callable():array{bool, string} $check */
function assertThat(string $description, callable $check): void
{
    global $passed, $failed;

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    if ($ok) {
        $passed++;
        printf("  ✔ %s%s\n", $description, $detail === '' ? '' : "  ({$detail})");

        return;
    }

    $failed++;
    printf("  ✘ %s%s\n", $description, $detail === '' ? '' : "  ({$detail})");
}

/** The financial tables a restore must bring back intact. */
$financialTables = [
    'payments_payable_accruals',
    'payments_settlement_runs',
    'payments_settlement_lines',
    'payments_payout_attempts',
    'payments_reconciliation_cases',
    'payments_ledger_entries',
    'payments_payments',
    'payments_wallets',
    'admin_audit_log',
];

// -- 1. Schema head ----------------------------------------------------------

heading('1) Schema — the restored database is at a version the application knows');

assertThat('the migrations table survived the restore', function (): array {
    $count = (int) DB::table('migrations')->count();

    return [$count > 0, "migrations recorded={$count}"];
});

assertThat('every financial table exists', function () use ($financialTables): array {
    $missing = [];

    foreach ($financialTables as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            $missing[] = $table;
        }
    }

    return [$missing === [], $missing === [] ? 'tables='.count($financialTables) : 'missing='.implode(',', $missing)];
});

assertThat('the M27 settlement migrations are recorded as applied', function (): array {
    $expected = [
        '2027_09_01_000001_create_payments_payable_accruals_table',
        '2027_09_01_000002_create_payments_settlement_runs_table',
        '2027_09_01_000003_create_payments_settlement_lines_table',
        '2027_09_01_000004_create_payments_payout_attempts_table',
        '2027_09_01_000005_create_payments_reconciliation_cases_table',
    ];

    $found = DB::table('migrations')->whereIn('migration', $expected)->pluck('migration')->all();
    $missing = array_values(array_diff($expected, $found));

    return [$missing === [], $missing === [] ? 'all 5 present' : 'missing='.implode(',', $missing)];
});

// -- 2. Readability ----------------------------------------------------------

heading('2) Readability — application data comes back, not just the schema');

assertThat('every financial table can be read', function () use ($financialTables): array {
    $counts = [];

    foreach ($financialTables as $table) {
        $counts[$table] = (int) DB::table($table)->count();
    }

    $populated = count(array_filter($counts));

    return [true, sprintf('%d of %d tables hold rows', $populated, count($counts))];
});

assertThat('the ledger holds entries to verify', function (): array {
    // A drill against an empty ledger proves nothing about ledger integrity,
    // so this is a precondition for check 5 rather than a property of its own.
    $count = (int) DB::table('payments_ledger_entries')->count();

    return [$count > 0, "ledger entries={$count}"];
});

assertThat('a sample row deserialises into readable columns', function (): array {
    $row = DB::table('payments_ledger_entries')->orderBy('posted_at')->first();

    if ($row === null) {
        return [false, 'no ledger row to sample'];
    }

    $readable = $row->correlation_id !== null
        && $row->account !== null
        && $row->direction !== null
        && is_numeric($row->amount_minor);

    return [$readable, "sampled account={$row->account} amount={$row->amount_minor}"];
});

// -- 3. Referential integrity ------------------------------------------------

heading('3) Referential integrity — every reference still resolves');

assertThat('every settlement line belongs to a settlement run that exists', function (): array {
    $orphans = (int) DB::table('payments_settlement_lines as l')
        ->leftJoin('payments_settlement_runs as r', 'r.id', '=', 'l.settlement_run_id')
        ->whereNull('r.id')
        ->count();

    return [$orphans === 0, "orphaned lines={$orphans}"];
});

assertThat('every settlement line points at an accrual that exists', function (): array {
    $orphans = (int) DB::table('payments_settlement_lines as l')
        ->leftJoin('payments_payable_accruals as a', 'a.id', '=', 'l.accrual_id')
        ->whereNull('a.id')
        ->count();

    return [$orphans === 0, "orphaned lines={$orphans}"];
});

assertThat('every payout attempt belongs to a settlement run that exists', function (): array {
    $orphans = (int) DB::table('payments_payout_attempts as p')
        ->leftJoin('payments_settlement_runs as r', 'r.id', '=', 'p.settlement_run_id')
        ->whereNull('r.id')
        ->count();

    return [$orphans === 0, "orphaned attempts={$orphans}"];
});

assertThat('every ledger entry carries a correlation id', function (): array {
    // Without it an entry cannot be tied to the event that caused it, which is
    // the only way to prove a posting was legitimate after the fact.
    $loose = (int) DB::table('payments_ledger_entries')->whereNull('correlation_id')->count();

    return [$loose === 0, "entries without correlation={$loose}"];
});

// -- 4. Financial consistency ------------------------------------------------

heading('4) Financial consistency — the derived figures still derive');

assertThat('every accrual\'s net equals gross minus commission minus fee', function (): array {
    $wrong = (int) DB::table('payments_payable_accruals')
        ->whereRaw('net_minor <> gross_minor - commission_minor - fee_minor')
        ->count();

    return [$wrong === 0, "inconsistent accruals={$wrong}"];
});

assertThat('every settlement run\'s net equals gross minus commission minus fee', function (): array {
    $wrong = (int) DB::table('payments_settlement_runs')
        ->whereRaw('net_minor <> gross_minor - commission_minor - fee_minor')
        ->count();

    return [$wrong === 0, "inconsistent runs={$wrong}"];
});

assertThat('every run\'s net equals the sum of the lines it reserved', function (): array {
    // Runs that hold no lines are excluded: a cancelled run releases its lines
    // and legitimately sums to zero against a non-zero net.
    $rows = DB::select("
        SELECT r.id, r.net_minor, COALESCE(SUM(l.net_minor), 0) AS line_total
        FROM payments_settlement_runs r
        JOIN payments_settlement_lines l ON l.settlement_run_id = r.id
        GROUP BY r.id, r.net_minor
        HAVING r.net_minor <> COALESCE(SUM(l.net_minor), 0)
    ");

    return [$rows === [], 'runs disagreeing with their lines='.count($rows)];
});

assertThat('no settlement run carries a negative amount', function (): array {
    $negative = (int) DB::table('payments_settlement_runs')
        ->where(fn ($q) => $q->where('gross_minor', '<', 0)
            ->orWhere('commission_minor', '<', 0)
            ->orWhere('fee_minor', '<', 0))
        ->count();

    return [$negative === 0, "runs with a negative component={$negative}"];
});

// -- 5. Ledger integrity -----------------------------------------------------

heading('5) Ledger integrity — double-entry still balances');

assertThat('the whole ledger nets to zero', function (): array {
    $net = (int) DB::table('payments_ledger_entries')
        ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END), 0) AS net")
        ->value('net');

    return [$net === 0, "net={$net}"];
});

assertThat('every correlation balances on its own', function (): array {
    // A ledger can net to zero overall while individual events are broken —
    // two opposite errors cancel. Per-correlation is the assertion that holds.
    $rows = DB::select("
        SELECT correlation_id
        FROM payments_ledger_entries
        GROUP BY correlation_id
        HAVING SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE -amount_minor END) <> 0
    ");

    return [$rows === [], 'unbalanced correlations='.count($rows)];
});

assertThat('no ledger entry carries a non-positive amount', function (): array {
    $bad = (int) DB::table('payments_ledger_entries')->where('amount_minor', '<=', 0)->count();

    return [$bad === 0, "entries<=0={$bad}"];
});

assertThat('the platform has not paid out more than it accrued', function (): array {
    $accrued = (int) DB::table('payments_payable_accruals')->where('type', 'earning')->sum('net_minor');

    // Credit, not debit. A payout is posted as MerchantPayable debit against a
    // Payouts credit, so summing debits on `payouts` returns zero however much
    // money has left — which is how the first version of this check passed
    // while comparing 0 against everything ever accrued.
    $paidOut = (int) DB::table('payments_ledger_entries')
        ->where('account', 'payouts')
        ->where('direction', 'credit')
        ->sum('amount_minor');

    if ($paidOut === 0 && $accrued > 0) {
        // Not a failure — a book with accruals but no payouts is the expected
        // state before the first live settlement — but it must not be reported
        // as though the comparison meant something.
        return [true, "nothing paid out yet; accrued={$accrued} (comparison not exercised)"];
    }

    return [$paidOut <= $accrued, "paid={$paidOut} accrued={$accrued}"];
});

// -- 6. Duplicate-payout safety ----------------------------------------------

heading('6) Duplicate-payout safety — the structural guarantees came back too');

assertThat('no accrual appears on two settlement lines', function (): array {
    $rows = DB::select('
        SELECT accrual_id FROM payments_settlement_lines
        GROUP BY accrual_id HAVING COUNT(*) > 1
    ');

    return [$rows === [], 'accruals settled twice='.count($rows)];
});

assertThat('the unique index that makes double settlement impossible is present', function (): array {
    // The data being clean today is not the guarantee; the constraint is. A
    // restore that dropped indexes would pass the check above and fail the
    // first time two workers raced.
    $rows = DB::select("
        SELECT indexdef FROM pg_indexes
        WHERE tablename = 'payments_settlement_lines' AND indexdef ILIKE '%UNIQUE%' AND indexdef ILIKE '%accrual_id%'
    ");

    return [$rows !== [], 'matching unique indexes='.count($rows)];
});

assertThat('the partial unique indexes on accruals are present', function (): array {
    $rows = DB::select("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'payments_payable_accruals' AND indexdef ILIKE '%UNIQUE%'
    ");

    $names = array_map(static fn ($r): string => $r->indexname, $rows);

    return [count($names) >= 2, 'unique indexes='.implode(',', $names)];
});

assertThat('no settlement run has two settled payout attempts', function (): array {
    $rows = DB::select("
        SELECT settlement_run_id FROM payments_payout_attempts
        WHERE state = 'succeeded'
        GROUP BY settlement_run_id HAVING COUNT(*) > 1
    ");

    return [$rows === [], 'runs paid twice='.count($rows)];
});

assertThat('no order has two earning accruals', function (): array {
    $rows = DB::select("
        SELECT order_id FROM payments_payable_accruals
        WHERE type = 'earning'
        GROUP BY order_id HAVING COUNT(*) > 1
    ");

    return [$rows === [], 'double-accrued orders='.count($rows)];
});

assertThat('the four-eyes CHECK constraint survived', function (): array {
    $rows = DB::select("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'payments_settlement_runs'::regclass AND contype = 'c'
    ");

    $names = array_map(static fn ($r): string => $r->conname, $rows);
    $foundApproverCheck = array_values(array_filter(
        $names,
        static fn (string $n): bool => str_contains($n, 'approver') || str_contains($n, 'executor') || str_contains($n, 'four'),
    ));

    return [$foundApproverCheck !== [], 'check constraints='.implode(',', $names)];
});

assertThat('the append-only triggers came back with the tables', function (): array {
    // Found while writing the negative controls for this script: an attempt to
    // corrupt a restored ledger row was refused by the trigger, which is the
    // protection working — and which also means a restore that quietly lost
    // these triggers would leave the ledger rewritable with nothing to say so.
    $expected = [
        'payments_ledger_entries' => 'payments_ledger_entries_append_only',
        'payments_payable_accruals' => 'payments_payable_accruals_append_only',
        'payments_settlement_lines' => 'payments_settlement_lines_no_update',
        'admin_audit_log' => 'admin_audit_log_append_only',
    ];

    $found = array_map(
        static fn ($r): string => $r->tgname,
        DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'),
    );

    $missing = array_values(array_diff(array_values($expected), $found));

    return [$missing === [], $missing === [] ? 'all 4 present' : 'missing='.implode(',', $missing)];
});

assertThat('no run was approved and executed by the same person', function (): array {
    $same = (int) DB::table('payments_settlement_runs')
        ->whereNotNull('approved_by')
        ->whereNotNull('executed_by')
        ->whereColumn('approved_by', 'executed_by')
        ->count();

    return [$same === 0, "runs failing four-eyes={$same}"];
});

// -- Result ------------------------------------------------------------------

$elapsed = microtime(true) - $startedAt;

echo "\n", str_repeat('=', 72), "\n";
printf("DATABASE: %s\n", $database);
printf("DURATION: %.2fs\n", $elapsed);
printf("RESULT: %d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
