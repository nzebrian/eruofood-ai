<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const RO_MERCHANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

/**
 * The report-only accrual cycle, end to end.
 *
 * This is the stage the rollout runs *before* any money moves: accruals are
 * recorded so finance can compare the platform's totals against the figures
 * they produce by hand, while nothing is settleable and the ledger is untouched.
 *
 * The point of testing it as a cycle rather than as isolated flags is that the
 * guarantee is a conjunction — accruals exist AND the ledger did not move AND
 * settlement refuses AND the totals are readable. Any one of those failing
 * makes the cycle worthless as a rehearsal.
 */
function roCapture(object $test, string $orderId, int $gross, string $email): void
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'RO Payer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $gross,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated();
}

it('records a full report-only cycle without moving a penny', function (): void {
    // Stage one of the rollout: accrual on, posting off. Nothing else.
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'false',
        'flags.overrides.settlement.compute' => 'true',
    ]);

    $ledger = app(\EruoFood\Payments\Application\Service\LedgerService::class);
    $payableBefore = $ledger->balanceOf(LedgerAccount::MerchantPayable);
    $payoutsBefore = $ledger->balanceOf(LedgerAccount::Payouts);

    $orders = [];
    foreach ([500_000, 300_000, 250_000] as $i => $gross) {
        $orderId = (string) Str::orderedUuid();
        roCapture($this, $orderId, $gross, "ro{$i}@example.com");
        app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', RO_MERCHANT));
        $orders[] = $orderId;
    }

    $accruals = app(PayableAccrualRepository::class);
    $totals = $accruals->totals();

    // 1. Every order produced an accrual, so the totals are complete.
    expect($totals['earnings'])->toBe(3)
        ->and($totals['gross_minor'])->toBe(1_050_000);

    // 2. Every one of them is report-only.
    expect($totals['reporting_only'])->toBe(3);

    // 3. The ledger did not move. This is the assertion that makes the cycle a
    //    rehearsal rather than a live run.
    expect($ledger->balanceOf(LedgerAccount::MerchantPayable))->toBe($payableBefore)
        ->and($ledger->balanceOf(LedgerAccount::Payouts))->toBe($payoutsBefore);

    // 4. Nothing is settleable, so the derived payable is zero even though the
    //    accruals total over a million minor units.
    expect($accruals->derivedPayableMinor('vendor', RO_MERCHANT, 'NGN'))->toBe(0);

    // 5. And settlement refuses outright rather than producing an empty run.
    expect(fn () => app(SettlementRunService::class)->computeDraft(
        'actor-1',
        'vendor',
        RO_MERCHANT,
        new DateTimeImmutable('-1 day'),
        new DateTimeImmutable('+1 day'),
    ))->toThrow(PaymentsInvalidState::class, 'nothing to settle');
});

it('makes accruals settleable only when posting is switched on, and not retroactively', function (): void {
    // The transition out of report-only. Accruals written during the cycle stay
    // report-only for ever — they are append-only, and their ledger movement
    // never happened. Only new ones are settleable.
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'false',
    ]);

    $duringCycle = (string) Str::orderedUuid();
    roCapture($this, $duringCycle, 400_000, 'during@example.com');
    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($duringCycle, 'vendor', RO_MERCHANT));

    // Posting switched on — the cycle ends.
    config(['flags.overrides.settlement.accrual_posting' => 'true']);

    $afterCycle = (string) Str::orderedUuid();
    roCapture($this, $afterCycle, 600_000, 'after@example.com');
    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($afterCycle, 'vendor', RO_MERCHANT));

    $accruals = app(PayableAccrualRepository::class);

    expect($accruals->findEarningForOrder($duringCycle)->isSettleable())->toBeFalse()
        ->and($accruals->findEarningForOrder($afterCycle)->isSettleable())->toBeTrue();

    // The payable reflects only the settleable one.
    $expected = $accruals->findEarningForOrder($afterCycle)->net()->minorUnits;
    expect($accruals->derivedPayableMinor('vendor', RO_MERCHANT, 'NGN'))->toBe($expected);
});

it('reports report-only totals through the operator command', function (): void {
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'false',
    ]);

    $orderId = (string) Str::orderedUuid();
    roCapture($this, $orderId, 750_000, 'cmd@example.com');
    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', RO_MERCHANT));

    $this->artisan('payments:settlement-report')
        ->expectsOutputToContain('Accruals')
        ->assertExitCode(0);

    // The number finance watches during the cycle.
    expect(app(PayableAccrualRepository::class)->totals()['reporting_only'])->toBe(1);
});
