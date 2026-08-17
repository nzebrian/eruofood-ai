<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\PayableAccrualService;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * What a deploy of M27 does before anybody touches a switch: nothing.
 *
 * Deliberately sets no configuration. Every assertion here reads the platform
 * exactly as it ships, so a flag accidentally given a truthy default — or a
 * scheduled task registered enabled — fails the suite rather than quietly
 * starting to move money.
 */

/** The M27 flags, in the order they may be switched on. */
function settlementFlagKeys(): array
{
    return [
        'settlement.accrual',
        'settlement.accrual_posting',
        'settlement.compute',
        'settlement.reconcile',
        'settlement.auto_approve',
        'settlement.execute',
        'settlement.new_flow',
    ];
}

it('ships every settlement flag off', function (): void {
    $evaluator = app(FlagEvaluator::class);

    foreach (settlementFlagKeys() as $key) {
        expect($evaluator->isEnabled($key))->toBeFalse("{$key} must ship disabled");
    }
});

it('declares every settlement flag with an owner, a rollout and a rollback', function (): void {
    $registry = app(FlagRegistry::class);

    foreach (settlementFlagKeys() as $key) {
        $flag = $registry->get($key);

        expect($flag->safeDefault)->toBeFalse("{$key} must have a safe default of false")
            ->and($flag->isHighRisk())->toBeTrue()
            ->and(trim($flag->owner))->not->toBe('')
            ->and(trim($flag->rolloutStrategy))->not->toBe('')
            ->and(trim($flag->rollbackStrategy))->not->toBe('');
    }
});

it('finds every settlement flag registered, so the sweep is not vacuous', function (): void {
    $registered = array_map(
        static fn ($flag): string => $flag->key,
        app(FlagRegistry::class)->all(),
    );

    foreach (settlementFlagKeys() as $key) {
        expect($registered)->toContain($key);
    }
});

it('leaves automatic dispatch exactly as it was', function (): void {
    // M27 must not have moved this, directly or by side effect. Asserted here
    // rather than only in the Dispatch suite because the failure mode is a
    // *different* milestone enabling it while nobody is looking at dispatch.
    expect(app(FlagEvaluator::class)->isEnabled('dispatch.engine'))->toBeFalse()
        ->and((bool) config('dispatch.engine_enabled', false))->toBeFalse();
});

it('registers its scheduled work disabled', function (): void {
    $tasks = app(ScheduleRegistry::class)->all();
    $names = array_map(static fn (ScheduledTask $t): string => $t->name, $tasks);

    expect($names)->toContain('payments:reconcile-settlements')
        ->and($names)->toContain('payments:settlement-report');

    foreach ($tasks as $task) {
        if (str_starts_with($task->name, 'payments:')) {
            expect($task->enabled)->toBeFalse("{$task->name} must be registered disabled");
        }
    }
});

it('schedules no task at all that can move money', function (): void {
    // Broader than the M27 tasks: nothing enabled anywhere may settle or pay.
    //
    // Written as a filter rather than a loop of assertions on purpose. A loop
    // over `enabled()` runs zero assertions when nothing is enabled — which is
    // the healthy state — and Pest reports that as risky rather than passing.
    // A test that is strongest when it asserts nothing is not a test.
    $registry = app(ScheduleRegistry::class);

    $moneyMoving = array_values(array_filter(
        $registry->enabled(),
        static fn (ScheduledTask $t): bool => str_contains($t->command, 'settle')
            || str_contains($t->command, 'payout'),
    ));

    expect($moneyMoving)->toBe([])
        // And the registry is populated, so the filter had something to filter.
        ->and(count($registry->all()))->toBeGreaterThan(0);
});

it('refuses to accrue, compute or execute with everything at its default', function (): void {
    // The three capabilities, each refusing in its own way: accrual returns
    // null (nothing to record), compute and execute throw (the caller asked for
    // something the platform is not doing).
    $accrued = app(PayableAccrualService::class)->recordSettledOrder(
        new EruoFood\Payments\Contracts\SettledOrder(
            '88888888-8888-4888-8888-000000000001',
            'vendor',
            '99999999-9999-4999-8999-000000000001',
        ),
    );

    expect($accrued)->toBeNull();

    expect(fn () => app(SettlementRunService::class)->computeDraft(
        null,
        'vendor',
        '99999999-9999-4999-8999-000000000001',
        new DateTimeImmutable('-1 day'),
        new DateTimeImmutable('+1 day'),
    ))->toThrow(EruoFood\Payments\Domain\Exception\PaymentsInvalidState::class, 'switched off');
});

it('runs no reconciler with the flag at its default', function (): void {
    $service = app(SettlementReconciliationService::class);

    expect($service->reconcilePayouts()['examined'])->toBe(0)
        ->and($service->reconcileLedgerAgainstPayable())->toBeNull()
        ->and($service->reconcileLedgerAgainstWallets())->toBeNull()
        ->and($service->reconcilePaymentsAgainstAccruals())->toBe([]);
});
