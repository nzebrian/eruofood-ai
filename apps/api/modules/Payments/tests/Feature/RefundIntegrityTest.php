<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Enum\RefundStatus;
use EruoFood\Payments\Domain\Payment\RefundRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\RefundModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M23 — a captured payment can never be refunded for more than it took.
 *
 * The old flow read the refundable balance, called the provider, then wrote the
 * result, with no lock and no reservation. Two requests could each see the full
 * balance and each send money. Refunds now reserve the amount as a `pending`
 * row inside a locked transaction, so the second request finds it already
 * claimed.
 */

/** @return array{token: string, paymentId: string} */
function refundablePayment(object $test, string $email, int $amountMinor = 100_000): array
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Refund Payer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $payment = $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $amountMinor,
        'customer_email' => $email,
        'method_type' => 'card',
        'provider' => 'mock',
    ])->assertCreated()->json('data');

    return ['token' => $token, 'paymentId' => $payment['payment_id']];
}

it('refuses a second refund that would exceed the captured amount', function (): void {
    ['token' => $token, 'paymentId' => $paymentId] = refundablePayment($this, 'refund-cap@example.com');

    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId,
        'amount_minor' => 60_000,
        'reason' => 'first',
    ])->assertCreated();

    // 60k is already gone; only 40k remains refundable.
    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId,
        'amount_minor' => 60_000,
        'reason' => 'second',
    ])->assertStatus(422);

    $refunded = (int) RefundModel::query()
        ->where('payment_id', $paymentId)
        ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Completed->value])
        ->sum('amount_minor');

    expect($refunded)->toBe(60_000);
});

it('counts a pending refund against the refundable balance', function (): void {
    ['token' => $token, 'paymentId' => $paymentId] = refundablePayment($this, 'refund-pending@example.com');

    // A refund the provider has not answered yet still reserves its amount —
    // otherwise a second request could spend the same money while it is in
    // flight.
    $refunds = app(RefundRepository::class);
    expect($refunds->reservedMinorFor($paymentId))->toBe(0);

    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId,
        'amount_minor' => 100_000,
        'reason' => 'full',
    ])->assertCreated();

    expect($refunds->reservedMinorFor($paymentId))->toBe(100_000);

    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId,
        'amount_minor' => 1,
        'reason' => 'over',
    ])->assertStatus(422);
});

it('replays the original refund when the request is retried with the same key', function (): void {
    ['token' => $token, 'paymentId' => $paymentId] = refundablePayment($this, 'refund-idem@example.com');

    $first = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'refund-retry-1')
        ->postJson('/api/v1/payments/refunds', [
            'payment_id' => $paymentId,
            'amount_minor' => 25_000,
            'reason' => 'damaged',
        ])->assertCreated()->json('data');

    $second = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'refund-retry-1')
        ->postJson('/api/v1/payments/refunds', [
            'payment_id' => $paymentId,
            'amount_minor' => 25_000,
            'reason' => 'damaged',
        ])->assertOk()->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and(RefundModel::query()->where('payment_id', $paymentId)->count())->toBe(1);
});

it('rejects an idempotency key reused for a different refund', function (): void {
    ['token' => $token, 'paymentId' => $paymentId] = refundablePayment($this, 'refund-reuse@example.com');

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'refund-key-x')
        ->postJson('/api/v1/payments/refunds', [
            'payment_id' => $paymentId,
            'amount_minor' => 10_000,
            'reason' => 'one',
        ])->assertCreated();

    // Same key, different amount: replaying the stored answer would be wrong,
    // so the request is refused outright.
    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'refund-key-x')
        ->postJson('/api/v1/payments/refunds', [
            'payment_id' => $paymentId,
            'amount_minor' => 30_000,
            'reason' => 'one',
        ])->assertStatus(422);

    expect(RefundModel::query()->where('payment_id', $paymentId)->count())->toBe(1);
});

it('keeps the ledger balanced across capture and refund', function (): void {
    ['token' => $token, 'paymentId' => $paymentId] = refundablePayment($this, 'refund-ledger@example.com');

    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId,
        'amount_minor' => 40_000,
        'reason' => 'partial',
    ])->assertCreated();

    $credits = (int) LedgerEntryModel::query()->where('direction', 'credit')->sum('amount_minor');
    $debits = (int) LedgerEntryModel::query()->where('direction', 'debit')->sum('amount_minor');

    expect($credits)->toBe($debits)
        ->and(app(EruoFood\Payments\Application\Service\LedgerIntegrityService::class)->verify()->isBalanced())
        ->toBeTrue();

    // The payment itself reflects the partial refund.
    expect((int) PaymentModel::query()->whereKey($paymentId)->value('refunded_minor'))->toBe(40_000);
});
