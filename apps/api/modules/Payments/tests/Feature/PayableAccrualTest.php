<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\PayableAccrualService;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\AccrualType;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\SettlementLine;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

const MERCHANT = '22222222-2222-4222-8222-222222222222';

/** Switch settlement flags on for one test. Everything defaults off. */
function settlementFlags(bool $accrual = true, bool $posting = true): void
{
    config([
        'flags.overrides.settlement.accrual' => $accrual ? 'true' : 'false',
        'flags.overrides.settlement.accrual_posting' => $posting ? 'true' : 'false',
    ]);
}

/**
 * Capture a real payment through the API so the ledger holds a genuine capture
 * posting — the accrual reads its figures from there, so a hand-built fixture
 * would test nothing.
 *
 * @return array{order: string, payment: string, gross: int}
 */
function capturedOrder(object $test, string $orderId, int $grossMinor = 1_000_000, string $email = 'accrual@example.com'): array
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Accrual Payer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $intent = $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $grossMinor,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated()->json('data');

    expect($intent['status'])->toBe('succeeded');

    return ['order' => $orderId, 'payment' => $intent['payment_id'], 'gross' => $grossMinor];
}

function recorder(): MerchantEarningsRecorder
{
    return app(MerchantEarningsRecorder::class);
}

function accruals(): PayableAccrualRepository
{
    return app(PayableAccrualRepository::class);
}

it('records nothing while the accrual flag is off', function (): void {
    // The resting state of the platform. No config manipulation here on
    // purpose — this is what a deploy of M27 does before anybody touches a flag.
    $order = '33333333-3333-4333-8333-000000000001';
    capturedOrder($this, $order, email: 'flagoff@example.com');

    $id = recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    expect($id)->toBeNull()
        ->and(accruals()->findEarningForOrder($order))->toBeNull();
});

it('derives the accrual from the ledger, not from any caller-supplied amount', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000002';
    $captured = capturedOrder($this, $order, 1_000_000, 'derived@example.com');

    $id = recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));
    expect($id)->not->toBeNull();

    $accrual = accruals()->findEarningForOrder($order);
    expect($accrual)->not->toBeNull();

    // The commission engine takes its cut at capture; the accrual must reflect
    // exactly what the ledger recorded, and its net must be the remainder.
    $ledger = app(\EruoFood\Payments\Domain\Ledger\LedgerRepository::class);
    $commissionMinor = 0;
    foreach ($ledger->forCorrelation($captured['payment']) as $entry) {
        if ($entry->account === LedgerAccount::Commission) {
            $commissionMinor += $entry->amount->minorUnits;
        }
    }

    expect($accrual->gross()->minorUnits)->toBe(1_000_000)
        ->and($accrual->commission()->minorUnits)->toBe($commissionMinor)
        ->and($accrual->net()->minorUnits)->toBe(1_000_000 - $commissionMinor - $accrual->fee()->minorUnits)
        ->and($accrual->paymentId())->toBe($captured['payment'])
        ->and($accrual->type())->toBe(AccrualType::Earning);
});

it('moves escrow to merchant payable when posting is enabled', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000003';
    capturedOrder($this, $order, 500_000, 'posted@example.com');

    $ledger = app(\EruoFood\Payments\Application\Service\LedgerService::class);
    $before = $ledger->balanceOf(LedgerAccount::MerchantPayable);

    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));
    $accrual = accruals()->findEarningForOrder($order);

    expect($accrual->ledgerPosted())->toBeTrue()
        ->and($ledger->balanceOf(LedgerAccount::MerchantPayable) - $before)->toBe($accrual->net()->minorUnits);
});

it('writes a report-only accrual that posts no ledger entry and cannot be settled', function (): void {
    // The report-only cycle: accruals recorded so finance can compare totals,
    // with nothing at stake because nothing is settleable.
    settlementFlags(accrual: true, posting: false);
    $order = '33333333-3333-4333-8333-000000000004';
    capturedOrder($this, $order, 400_000, 'reportonly@example.com');

    $ledger = app(\EruoFood\Payments\Application\Service\LedgerService::class);
    $before = $ledger->balanceOf(LedgerAccount::MerchantPayable);

    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));
    $accrual = accruals()->findEarningForOrder($order);

    expect($accrual)->not->toBeNull()
        ->and($accrual->ledgerPosted())->toBeFalse()
        ->and($accrual->isSettleable())->toBeFalse()
        ->and($ledger->balanceOf(LedgerAccount::MerchantPayable))->toBe($before);

    // And it is invisible to the settlement query, not merely refused later.
    expect(accruals()->unsettledEarnings('vendor', MERCHANT, 'NGN', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')))
        ->toBe([]);
});

it('refuses to build a settlement line from a report-only accrual', function (): void {
    settlementFlags(accrual: true, posting: false);
    $order = '33333333-3333-4333-8333-000000000005';
    capturedOrder($this, $order, 400_000, 'noline@example.com');
    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    $accrual = accruals()->findEarningForOrder($order);

    expect(fn () => SettlementLine::forAccrual('line-1', 'run-1', $accrual, new DateTimeImmutable()))
        ->toThrow(\EruoFood\Payments\Domain\Exception\PaymentsInvalidState::class, 'report-only');
});

it('accrues an order exactly once however many times it is reported', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000006';
    capturedOrder($this, $order, 700_000, 'once@example.com');

    $settled = new SettledOrder($order, 'vendor', MERCHANT);
    $first = recorder()->recordSettledOrder($settled);
    $second = recorder()->recordSettledOrder($settled);
    $third = recorder()->recordSettledOrder($settled);

    expect($first)->toBe($second)->and($second)->toBe($third);

    $count = \EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayableAccrualModel::query()
        ->where('order_id', $order)->where('type', AccrualType::Earning->value)->count();

    expect($count)->toBe(1);
});

it('lets the database arbitrate a duplicate rather than a read-then-write check', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000007';
    capturedOrder($this, $order, 300_000, 'dbunique@example.com');
    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    // Bypass the service entirely and insert straight into the repository, the
    // way a concurrent worker that had already passed the "does it exist" check
    // would. The partial unique index is what stops it.
    $duplicate = PayableAccrual::accrue(
        id: (string) Illuminate\Support\Str::orderedUuid(),
        merchantType: 'vendor',
        merchantId: MERCHANT,
        orderId: $order,
        paymentId: (string) Illuminate\Support\Str::orderedUuid(),
        gross: new Money(300_000),
        commission: new Money(0),
        fee: new Money(0),
        commissionRateBps: 0,
        ledgerPosted: true,
        correlationId: 'test',
        now: new DateTimeImmutable(),
    );

    expect(fn () => accruals()->insert($duplicate))
        ->toThrow(\EruoFood\Payments\Domain\Exception\PaymentsConflict::class);
});

it('accrues nothing for an order that was never paid', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000008';

    expect(recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT)))->toBeNull();
});

it('reduces the payable by a refund without editing the earning', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000009';
    capturedOrder($this, $order, 1_000_000, 'refunded@example.com');
    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    $earning = accruals()->findEarningForOrder($order);
    $before = accruals()->derivedPayableMinor('vendor', MERCHANT, 'NGN');

    /** @var PayableAccrualService $service */
    $service = app(PayableAccrualService::class);
    $refundId = (string) Illuminate\Support\Str::orderedUuid();
    $service->recordRefund($order, $refundId, new Money(250_000));

    $after = accruals()->derivedPayableMinor('vendor', MERCHANT, 'NGN');

    expect($after)->toBe($before - 250_000)
        // The earning row is untouched — the reduction is a second row.
        ->and(accruals()->findEarningForOrder($order)->net()->minorUnits)->toBe($earning->net()->minorUnits);

    $adjustments = \EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayableAccrualModel::query()
        ->where('order_id', $order)->where('type', AccrualType::RefundAdjustment->value)->count();

    expect($adjustments)->toBe(1);
});

it('applies a duplicated refund to the payable only once', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000010';
    capturedOrder($this, $order, 800_000, 'duprefund@example.com');
    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    /** @var PayableAccrualService $service */
    $service = app(PayableAccrualService::class);
    $refundId = (string) Illuminate\Support\Str::orderedUuid();

    $before = accruals()->derivedPayableMinor('vendor', MERCHANT, 'NGN');
    $service->recordRefund($order, $refundId, new Money(100_000));
    $service->recordRefund($order, $refundId, new Money(100_000));
    $after = accruals()->derivedPayableMinor('vendor', MERCHANT, 'NGN');

    expect($before - $after)->toBe(100_000);
});

it('keeps the ledger balanced through accrual and refund adjustment', function (): void {
    settlementFlags();
    $order = '33333333-3333-4333-8333-000000000011';
    capturedOrder($this, $order, 900_000, 'balanced@example.com');
    recorder()->recordSettledOrder(new SettledOrder($order, 'vendor', MERCHANT));

    /** @var PayableAccrualService $service */
    $service = app(PayableAccrualService::class);
    $service->recordRefund($order, (string) Illuminate\Support\Str::orderedUuid(), new Money(150_000));

    $ledger = app(\EruoFood\Payments\Domain\Ledger\LedgerRepository::class);

    expect($ledger->netMinor())->toBe(0)
        ->and($ledger->unbalancedCorrelations())->toBe([]);
});

it('refuses an accrual whose commission exceeds its gross', function (): void {
    expect(fn () => PayableAccrual::accrue(
        id: 'a',
        merchantType: 'vendor',
        merchantId: MERCHANT,
        orderId: 'o',
        paymentId: 'p',
        gross: new Money(1000),
        commission: new Money(1200),
        fee: new Money(0),
        commissionRateBps: 0,
        ledgerPosted: true,
        correlationId: 'c',
        now: new DateTimeImmutable(),
    ))->toThrow(\EruoFood\Payments\Domain\Exception\PaymentsInvalidState::class, 'negative net');
});

it('refuses an accrual that mixes currencies', function (): void {
    expect(fn () => PayableAccrual::accrue(
        id: 'a',
        merchantType: 'vendor',
        merchantId: MERCHANT,
        orderId: 'o',
        paymentId: 'p',
        gross: new Money(1000, 'NGN'),
        commission: new Money(100, 'USD'),
        fee: new Money(0, 'NGN'),
        commissionRateBps: 0,
        ledgerPosted: true,
        correlationId: 'c',
        now: new DateTimeImmutable(),
    ))->toThrow(\EruoFood\Payments\Domain\Exception\PaymentsInvalidState::class, 'mix currencies');
});
