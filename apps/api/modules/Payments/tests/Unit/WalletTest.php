<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Shared\Domain\ValueObject\Money;

function wngn(int $minor): Money
{
    return new Money($minor, 'NGN');
}

function newWallet(int $low = 50000): Wallet
{
    return Wallet::open('w1', WalletOwnerType::Customer, 'user-1', 'NGN', $low, new DateTimeImmutable());
}

it('credits and debits and tracks the running balance', function (): void {
    $wallet = newWallet();
    $wallet->credit(wngn(200000), TransactionType::Topup, null, 'Top-up', 't1', new DateTimeImmutable());
    expect($wallet->balance()->minorUnits)->toBe(200000);

    $wallet->debit(wngn(50000), TransactionType::Payment, 'order-1', 'Order', 't2', new DateTimeImmutable());
    expect($wallet->balance()->minorUnits)->toBe(150000);

    $txns = $wallet->releaseNewTransactions();
    expect($txns)->toHaveCount(2)
        ->and($txns[1]->balanceAfter->minorUnits)->toBe(150000);
});

it('refuses to overdraw', function (): void {
    $wallet = newWallet();
    $wallet->credit(wngn(10000), TransactionType::Topup, null, null, 't1', new DateTimeImmutable());
    expect(fn () => $wallet->debit(wngn(20000), TransactionType::Payment, null, null, 't2', new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class);
});

it('emits a credit event and a low-balance event', function (): void {
    $wallet = newWallet(low: 100000);
    $wallet->credit(wngn(120000), TransactionType::Topup, null, null, 't1', new DateTimeImmutable());
    $wallet->debit(wngn(90000), TransactionType::Payment, null, null, 't2', new DateTimeImmutable()); // balance 30000 <= 100000
    $events = $wallet->releaseEvents();
    // one WalletCredited + one WalletLowBalance
    expect($events)->toHaveCount(2);
});
