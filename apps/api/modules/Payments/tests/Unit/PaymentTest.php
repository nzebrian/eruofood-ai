<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Shared\Domain\ValueObject\Money;

function pngn(int $minor): Money
{
    return new Money($minor, 'NGN');
}

function newPayment(array $splits = []): Payment
{
    return Payment::initiate(
        'pay1',
        'PMT-REF',
        'order-1',
        'user-1',
        pngn(1000000),
        PaymentProvider::Mock,
        PaymentMethodType::Card,
        'idem-1',
        $splits,
        new DateTimeImmutable('2026-07-27T10:00:00Z'),
    );
}

it('initiates pending and captures with a success event', function (): void {
    $payment = newPayment();
    expect($payment->status())->toBe(PaymentStatus::Pending);

    $payment->markSucceeded(new DateTimeImmutable());
    expect($payment->status())->toBe(PaymentStatus::Succeeded)
        ->and($payment->releaseEvents())->toHaveCount(1);
});

it('is idempotent on a repeated capture', function (): void {
    $payment = newPayment();
    $payment->markSucceeded(new DateTimeImmutable());
    $payment->releaseEvents();
    $payment->markSucceeded(new DateTimeImmutable()); // no-op
    expect($payment->releaseEvents())->toHaveCount(0);
});

it('rejects splits that exceed the amount', function (): void {
    expect(fn () => newPayment([new PaymentSplit('vendor', 'v1', pngn(2000000))]))
        ->toThrow(PaymentsInvalidState::class);
});

it('applies partial then full refunds and blocks over-refund', function (): void {
    $payment = newPayment();
    $payment->markSucceeded(new DateTimeImmutable());

    $fully = $payment->applyRefund(pngn(400000), new DateTimeImmutable());
    expect($fully)->toBeFalse()
        ->and($payment->status())->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->refundableAmount()->minorUnits)->toBe(600000);

    $fully = $payment->applyRefund(pngn(600000), new DateTimeImmutable());
    expect($fully)->toBeTrue()
        ->and($payment->status())->toBe(PaymentStatus::Refunded);

    expect(fn () => $payment->applyRefund(pngn(1), new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class);
});

it('cannot refund an uncaptured payment', function (): void {
    $payment = newPayment();
    expect(fn () => $payment->applyRefund(pngn(1), new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class);
});
