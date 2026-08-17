<?php

declare(strict_types=1);

/**
 * M27 — negative-control (false-positive) audit.
 *
 * A passing test proves nothing on its own. It might be asserting something
 * that is true for a reason other than the protection it claims to check — a
 * strict comparison that never matches, a scan that finds no files, a guard
 * that is redundant with one further down.
 *
 * So for each safety-critical protection this script **removes the protection**,
 * runs the test that claims to cover it, and requires the test to FAIL. A
 * protection whose test still passes without it is a false positive, and is
 * reported as such rather than quietly accepted.
 *
 * Every mutation is applied to a copy-on-write backup and reverted immediately,
 * including on failure. Run: php scripts/m27_negative_control_audit.php
 */

$root = dirname(__DIR__);
$backups = [];

/**
 * @param list<array{file: string, find: string, replace: string}> $mutations
 */
function mutate(array $mutations, string $root, array &$backups): bool
{
    foreach ($mutations as $m) {
        $path = $root.'/'.$m['file'];
        $original = file_get_contents($path);
        if ($original === false) {
            fwrite(STDERR, "cannot read {$m['file']}\n");

            return false;
        }
        if (! str_contains($original, $m['find'])) {
            fwrite(STDERR, "MUTATION TARGET NOT FOUND in {$m['file']}: ".substr($m['find'], 0, 70)."\n");

            return false;
        }
        $backups[$path] ??= $original;
        file_put_contents($path, str_replace($m['find'], $m['replace'], $original));
    }

    return true;
}

function restore(array &$backups): void
{
    foreach ($backups as $path => $content) {
        file_put_contents($path, $content);
    }
    $backups = [];
}

function runTests(string $root, string $target): bool
{
    $cmd = sprintf('cd %s && ./vendor/bin/pest %s 2>&1', escapeshellarg($root), escapeshellarg($target));
    exec($cmd, $out, $code);

    return $code === 0;
}

/*
 * Each entry: the protection, the test that claims to cover it, and the edit
 * that removes it. `expectFailure` is always true — that is the whole point.
 */
$controls = [
    [
        'name' => 'settlement_lines.accrual_id is unique (an accrual is paid for once, ever)',
        'test' => 'modules/Payments/tests/Feature/SettlementLifecycleTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Infrastructure/Persistence/Migration/2027_09_01_000003_create_payments_settlement_lines_table.php',
            'find' => "\$table->unique('accrual_id', 'payments_settlement_lines_accrual_unique');",
            'replace' => "\$table->index('accrual_id', 'payments_settlement_lines_accrual_unique');",
        ]],
    ],
    [
        'name' => 'the live-window partial unique index (one settlement per merchant and period)',
        'test' => 'modules/Payments/tests/Feature/SettlementLifecycleTest.php',
        'mutations' => [
            [
                'file' => 'modules/Payments/src/Infrastructure/Persistence/Migration/2027_09_01_000002_create_payments_settlement_runs_table.php',
                'find' => "            CREATE UNIQUE INDEX payments_settlement_runs_live_window_unique",
                'replace' => "            CREATE INDEX payments_settlement_runs_live_window_unique",
            ],
            // Also remove the service's courtesy check, so only the index is
            // under test rather than the friendly error in front of it.
            [
                'file' => 'modules/Payments/src/Application/Service/SettlementRunService.php',
                'find' => '            $existing = $this->runs->liveRunForWindow($merchantType, $merchantId, $ccy, $windowStart, $windowEnd);',
                'replace' => '            $existing = null;',
            ],
        ],
    ],
    [
        'name' => 'the accrual order partial unique index (an order accrues once)',
        'test' => 'modules/Payments/tests/Feature/PayableAccrualTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Infrastructure/Persistence/Migration/2027_09_01_000001_create_payments_payable_accruals_table.php',
            'find' => "            CREATE UNIQUE INDEX payments_payable_accruals_order_earning_unique",
            'replace' => "            CREATE INDEX payments_payable_accruals_order_earning_unique",
        ]],
    ],
    [
        'name' => 'the refund partial unique index (a refund reduces the payable once)',
        'test' => 'modules/Payments/tests/Feature/PayableAccrualTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Infrastructure/Persistence/Migration/2027_09_01_000001_create_payments_payable_accruals_table.php',
            'find' => "            CREATE UNIQUE INDEX payments_payable_accruals_refund_unique",
            'replace' => "            CREATE INDEX payments_payable_accruals_refund_unique",
        ]],
    ],
    [
        'name' => 'report-only accruals cannot be settled',
        'test' => 'modules/Payments/tests/Feature/PayableAccrualTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Settlement/PayableAccrual.php',
            'find' => 'return $this->ledgerPosted && $this->type === AccrualType::Earning;',
            'replace' => 'return $this->type === AccrualType::Earning;',
        ]],
    ],
    [
        'name' => 'money-moving routes are off the read permission (F5)',
        'test' => 'modules/Payments/tests/Feature/SettlementAuthorizationTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Interface/Http/routes.php',
            'find' => "'permission:finance.settle'])->prefix('admin')",
            'replace' => "'permission:finance.read'])->prefix('admin')",
        ]],
    ],
    [
        'name' => 'the approver cannot also execute (separation of duties)',
        'test' => 'modules/Payments/tests/Feature/SettlementLifecycleTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Settlement/SettlementRun.php',
            'find' => 'if ($this->approvedBy !== null && $this->approvedBy === $actorId) {',
            'replace' => 'if (false) {',
        ]],
    ],
    [
        'name' => 'an unknown run has no transition back into a money-moving state',
        'test' => 'modules/Payments/tests/Feature/SettlementLifecycleTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Enum/SettlementRunState.php',
            'find' => 'self::Unknown => [self::Reconciling],',
            'replace' => 'self::Unknown => [self::Reconciling, self::Pending],',
        ]],
    ],
    [
        'name' => 'an unknown gateway outcome is not safely retryable',
        'test' => 'modules/Payments/tests/Unit/GatewayOutcomeTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Enum/GatewayOutcome.php',
            'find' => 'return $this === self::Failed;',
            'replace' => 'return $this === self::Failed || $this === self::Unknown;',
        ]],
    ],
    [
        'name' => 'an unrecognised legacy failure resolves to unknown, not failed',
        'test' => 'modules/Payments/tests/Unit/GatewayOutcomeTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Enum/GatewayOutcome.php',
            'find' => "default => self::Unknown,\n        };\n    }\n}",
            'replace' => "default => self::Failed,\n        };\n    }\n}",
        ]],
    ],
    [
        'name' => 'an adjusted resolution requires a compensating posting',
        'test' => 'modules/Payments/tests/Feature/ReconciliationCaseTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Settlement/ReconciliationCase.php',
            'find' => "        if (trim(\$compensatingPostingId) === '') {",
            'replace' => '        if (false) {',
        ]],
    ],
    [
        'name' => 'a drift between two internal numbers cannot close itself',
        'test' => 'modules/Payments/tests/Feature/ReconciliationCaseTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Enum/DiscrepancyKind.php',
            'find' => 'return $this === self::PayoutStateMismatch;',
            'replace' => 'return true;',
        ]],
    ],
    [
        'name' => 'merchant endpoints are scoped to the caller (IDOR)',
        'test' => 'modules/Payments/tests/Feature/MerchantSettlementApiTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Interface/Http/Controller/MerchantSettlementController.php',
            'find' => 'if (! in_array($merchantId, $this->merchants->merchantsFor($userId), true)) {',
            'replace' => 'if (false) {',
        ]],
    ],
    [
        'name' => 'every settlement flag ships off',
        'test' => 'modules/Payments/tests/Feature/SettlementSafeDefaultsTest.php',
        'mutations' => [[
            'file' => 'modules/Shared/src/Infrastructure/Provider/SharedServiceProvider.php',
            'find' => "key: 'settlement.execute',\n                safeDefault: false,",
            'replace' => "key: 'settlement.execute',\n                safeDefault: true,",
        ]],
    ],
    [
        'name' => 'settlement scheduled work is registered disabled',
        'test' => 'modules/Payments/tests/Feature/SettlementSafeDefaultsTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Infrastructure/Provider/PaymentsServiceProvider.php',
            'find' => "command: 'payments:reconcile-settlements',\n            cadence: Cadence::Hourly,\n            enabled: false,",
            'replace' => "command: 'payments:reconcile-settlements',\n            cadence: Cadence::Hourly,\n            enabled: true,",
        ]],
    ],
    [
        'name' => 'a settlement run must pay out a positive amount',
        'test' => 'modules/Payments/tests/Feature/ReconciliationCaseTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Settlement/SettlementRun.php',
            'find' => 'if ($net->minorUnits <= 0) {',
            'replace' => 'if (false) {',
        ]],
    ],
    [
        'name' => 'a settlement run refuses mixed currencies',
        'test' => 'modules/Payments/tests/Feature/ReconciliationCaseTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Domain/Settlement/SettlementRun.php',
            'find' => "                throw new PaymentsInvalidState('A settlement run cannot mix currencies.');",
            'replace' => '                $amount = $amount;',
        ]],
    ],
    [
        'name' => 'a live run does not release its accruals',
        'test' => 'modules/Payments/tests/Feature/ReconciliationCaseTest.php',
        'mutations' => [[
            'file' => 'modules/Payments/src/Infrastructure/Persistence/Eloquent/EloquentSettlementRunRepository.php',
            'find' => 'if (! $run->state()->releasesAccruals()) {',
            'replace' => 'if (false) {',
        ]],
    ],
];

echo "EruoFood — M27 negative-control audit\n";
echo str_repeat('=', 78)."\n";
echo "Each protection is removed; its test must then FAIL.\n\n";

$confirmed = 0;
$falsePositives = [];
$broken = [];

foreach ($controls as $control) {
    printf("%-72s", substr($control['name'], 0, 72));

    if (! mutate($control['mutations'], $root, $backups)) {
        restore($backups);
        $broken[] = $control['name'];
        echo " SKIP\n";

        continue;
    }

    $stillPasses = runTests($root, $control['test']);
    restore($backups);

    if ($stillPasses) {
        $falsePositives[] = $control['name'];
        echo " FALSE POSITIVE\n";
    } else {
        $confirmed++;
        echo " ok\n";
    }
}

echo "\n".str_repeat('=', 78)."\n";
printf("%d/%d protections confirmed by their tests.\n", $confirmed, count($controls));

if ($broken !== []) {
    echo "\nMutation target not found (the audit itself needs updating):\n";
    foreach ($broken as $name) {
        echo "  - {$name}\n";
    }
}

if ($falsePositives !== []) {
    echo "\nFALSE POSITIVES — these tests pass without the protection they claim to check:\n";
    foreach ($falsePositives as $name) {
        echo "  - {$name}\n";
    }
}

exit($falsePositives === [] && $broken === [] ? 0 : 1);
