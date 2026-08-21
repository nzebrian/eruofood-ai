<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\LedgerService;
use EruoFood\Payments\Application\Service\PayableAccrualService;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayableAccrualModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayoutAttemptModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\ReconciliationCaseModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementRunModel;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MockGateway;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M28 Phase 9 — does switching it off actually stop the money?
 *
 * {@see SettlementSafeDefaultsTest} proves the switches ship off. That is a
 * different and weaker claim than the one an operator needs during an incident,
 * which is: *if I turn this off, does the platform stop paying people?* A flag
 * that is read in one branch and forgotten in another ships off and still pays.
 *
 * ## Every assertion here is paired
 *
 * For each switch there are two tests. The first turns it off and asserts that
 * nothing happened — no row, no ledger entry, and for the payout path no call
 * to the provider at all. The second turns the same switch on and asserts the
 * same operation *does* happen.
 *
 * The pairing is the point. An "off" test passes just as happily when the
 * fixture is broken and the operation could never have worked in the first
 * place; the M27 negative-control audit exists because that failure mode is
 * invisible from a green suite. Here the control lives beside the assertion it
 * protects, so a fixture that stops working takes the paired test down with it.
 *
 * ## Why the provider call is counted separately
 *
 * Absence of a payout row proves nothing was *recorded*. Only
 * {@see MockGateway::transferCount()} proves nothing was *sent*. Against a real
 * gateway those are the same question asked either side of the moment the money
 * leaves, and M27 exists because the gap between them is where an unknown
 * outcome is born.
 */
const KS_VENDOR = '4a4a4a4a-4444-4444-8444-4444444444aa';
const KS_APPROVER = '5a5a5a5a-5555-4555-8555-5555555555aa';
const KS_EXECUTOR = '6a6a6a6a-6666-4666-8666-6666666666aa';

beforeEach(function (): void {
    MockGateway::reset();
});

/**
 * Switch on everything up to, but not including, the flag under test.
 *
 * Expressed as "everything except" rather than a list per test so that adding a
 * new settlement flag cannot silently leave a kill switch untested.
 */
function ksEnableAllExcept(string ...$disabled): void
{
    $all = [
        'settlement.accrual',
        'settlement.accrual_posting',
        'settlement.compute',
        'settlement.execute',
        'settlement.reconcile',
    ];

    $overrides = [];
    foreach ($all as $flag) {
        $overrides["flags.overrides.{$flag}"] = in_array($flag, $disabled, true) ? 'false' : 'true';
    }

    config($overrides);
}

/** Capture a real payment and accrue it, so the payable is derived, never supplied. */
function ksAccrue(object $test, string $orderId, int $grossMinor, string $email): void
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $grossMinor,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated();

    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', KS_VENDOR));
}

function ksWindow(): array
{
    return [new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')];
}

function ksBank(): BankAccount
{
    return new BankAccount('0123456789', '058', 'Kill Switch Vendor');
}

/** Drive a merchant all the way to an approved run, ready to be paid. */
function ksApprovedRun(object $test, string $orderId, string $email): string
{
    ksAccrue($test, $orderId, 1_000_000, $email);
    [$from, $to] = ksWindow();
    $run = app(SettlementRunService::class)->computeDraft(KS_APPROVER, 'vendor', KS_VENDOR, $from, $to);
    app(SettlementRunService::class)->approve(KS_APPROVER, $run->id());

    return $run->id();
}

/** What has actually left the platform towards merchants. */
function ksPaidOutMinor(): int
{
    return app(LedgerService::class)->balanceOf(LedgerAccount::Payouts);
}

describe('settlement.execute — the financial kill switch', function (): void {
    it('sends nothing to the provider while it is off', function (): void {
        ksEnableAllExcept('settlement.execute');
        $runId = ksApprovedRun($this, '7a000000-0000-4000-8000-000000000001', 'ks-exec-off@example.com');

        expect(fn () => app(SettlementRunService::class)->execute(KS_EXECUTOR, $runId, ksBank()))
            ->toThrow(PaymentsInvalidState::class, 'switched off');

        // Nothing sent, nothing recorded, nothing posted.
        expect(MockGateway::transferCount())->toBe(0)
            ->and(PayoutAttemptModel::query()->count())->toBe(0)
            ->and(ksPaidOutMinor())->toBe(0);
    });

    it('pays out once the same switch is on — the control for the test above', function (): void {
        ksEnableAllExcept();
        $runId = ksApprovedRun($this, '7a000000-0000-4000-8000-000000000002', 'ks-exec-on@example.com');

        app(SettlementRunService::class)->execute(KS_EXECUTOR, $runId, ksBank());

        expect(MockGateway::transferCount())->toBe(1)
            ->and(PayoutAttemptModel::query()->count())->toBe(1);
    });

    it('stops paying the moment it is switched off mid-flight', function (): void {
        // The incident case: a run is approved and ready, the switch is thrown,
        // and the next execute must refuse rather than honour work already
        // queued up behind it.
        ksEnableAllExcept();
        $runId = ksApprovedRun($this, '7a000000-0000-4000-8000-000000000003', 'ks-exec-mid@example.com');

        config(['flags.overrides.settlement.execute' => 'false']);

        expect(fn () => app(SettlementRunService::class)->execute(KS_EXECUTOR, $runId, ksBank()))
            ->toThrow(PaymentsInvalidState::class, 'switched off');

        expect(MockGateway::transferCount())->toBe(0)
            ->and(PayoutAttemptModel::query()->count())->toBe(0);
    });
});

describe('settlement.compute — nothing to approve', function (): void {
    it('creates no settlement run while it is off', function (): void {
        ksEnableAllExcept('settlement.compute');
        ksAccrue($this, '7a000000-0000-4000-8000-000000000004', 800_000, 'ks-compute-off@example.com');
        [$from, $to] = ksWindow();

        expect(fn () => app(SettlementRunService::class)->computeDraft(KS_APPROVER, 'vendor', KS_VENDOR, $from, $to))
            ->toThrow(PaymentsInvalidState::class, 'switched off');

        expect(SettlementRunModel::query()->count())->toBe(0);
    });

    it('creates a run once the same switch is on', function (): void {
        ksEnableAllExcept();
        ksAccrue($this, '7a000000-0000-4000-8000-000000000005', 800_000, 'ks-compute-on@example.com');
        [$from, $to] = ksWindow();

        app(SettlementRunService::class)->computeDraft(KS_APPROVER, 'vendor', KS_VENDOR, $from, $to);

        expect(SettlementRunModel::query()->count())->toBe(1);
    });
});

describe('settlement.accrual — nothing owed', function (): void {
    it('records no accrual while it is off', function (): void {
        ksEnableAllExcept('settlement.accrual', 'settlement.accrual_posting');
        ksAccrue($this, '7a000000-0000-4000-8000-000000000006', 700_000, 'ks-accr-off@example.com');

        expect(PayableAccrualModel::query()->count())->toBe(0);
    });

    it('records an accrual once the same switch is on', function (): void {
        ksEnableAllExcept();
        ksAccrue($this, '7a000000-0000-4000-8000-000000000007', 700_000, 'ks-accr-on@example.com');

        expect(PayableAccrualModel::query()->count())->toBe(1);
    });
});

describe('settlement.accrual_posting — report-only accrual', function (): void {
    it('records the accrual but posts nothing to the ledger while it is off', function (): void {
        // This is the report-only cycle: the platform learns what it owes
        // without the accrual becoming settleable.
        ksEnableAllExcept('settlement.accrual_posting');
        ksAccrue($this, '7a000000-0000-4000-8000-000000000008', 900_000, 'ks-post-off@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('7a000000-0000-4000-8000-000000000008');

        expect($accrual)->not->toBeNull()
            ->and($accrual->ledgerPosted())->toBeFalse();
    });

    it('posts the accrual to the ledger once the same switch is on', function (): void {
        ksEnableAllExcept();
        ksAccrue($this, '7a000000-0000-4000-8000-000000000009', 900_000, 'ks-post-on@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('7a000000-0000-4000-8000-000000000009');

        expect($accrual->ledgerPosted())->toBeTrue();
    });
});

describe('settlement.reconcile — the sweeps stay still', function (): void {
    it('examines nothing and opens no case while it is off', function (): void {
        ksEnableAllExcept('settlement.reconcile');

        $service = app(SettlementReconciliationService::class);

        expect($service->reconcilePayouts()['examined'])->toBe(0)
            ->and($service->reconcilePaymentsAgainstAccruals())->toBe([])
            ->and($service->reconcileLedgerAgainstPayable())->toBeNull()
            ->and($service->reconcileLedgerAgainstWallets())->toBeNull()
            ->and(ReconciliationCaseModel::query()->count())->toBe(0);
    });

    it('examines an outstanding payout once the same switch is on', function (): void {
        // The control has to leave the reconciler something to examine,
        // otherwise "examined 0" would be indistinguishable from the flag
        // having stopped it — which is precisely the assertion above.
        ksEnableAllExcept();
        MockGateway::nextTransfer(GatewayOutcome::Unknown);
        $runId = ksApprovedRun($this, '7a000000-0000-4000-8000-00000000000a', 'ks-recon-on@example.com');
        app(SettlementRunService::class)->execute(KS_EXECUTOR, $runId, ksBank());

        $summary = app(SettlementReconciliationService::class)->reconcilePayouts();

        expect($summary['examined'])->toBeGreaterThan(0);
    });
});

describe('dispatch.engine — unchanged by anything financial', function (): void {
    it('is off, and off through both switches that could turn it on', function (): void {
        expect(app(FlagEvaluator::class)->isEnabled('dispatch.engine'))->toBeFalse()
            ->and((bool) config('dispatch.engine.enabled', false))->toBeFalse()
            ->and((bool) config('dispatch.engine_enabled', false))->toBeFalse();
    });
});

it('never moves money with every switch at its shipped default', function (): void {
    // No config() call anywhere in this test: the platform exactly as deployed.
    ksAccrue($this, '7a000000-0000-4000-8000-00000000000b', 1_000_000, 'ks-default@example.com');

    expect(PayableAccrualModel::query()->count())->toBe(0)
        ->and(SettlementRunModel::query()->count())->toBe(0)
        ->and(PayoutAttemptModel::query()->count())->toBe(0)
        ->and(MockGateway::transferCount())->toBe(0)
        ->and(ksPaidOutMinor())->toBe(0);

    expect(app(PayableAccrualService::class)->recordSettledOrder(
        new SettledOrder('7a000000-0000-4000-8000-00000000000c', 'vendor', KS_VENDOR),
    ))->toBeNull();
});
