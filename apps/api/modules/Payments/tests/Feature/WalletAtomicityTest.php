<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WalletModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WalletTransactionModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M23 — the transaction boundary around wallet movements.
 *
 * True concurrency cannot be exercised here: RefreshDatabase wraps each test in
 * a transaction, so a second connection would never see this one's writes. Real
 * parallel behaviour is proven by `scripts/financial_concurrency_validation.php`
 * against PostgreSQL. What these tests pin down is the other half — that a
 * failure part-way through leaves *nothing* behind.
 */
function walletService(): WalletService
{
    return app(WalletService::class);
}

it('rolls the debit back when the matching credit fails, so no money is destroyed', function (): void {
    $wallets = app(WalletRepository::class);
    $service = walletService();

    $source = $service->getOrOpen(WalletOwnerType::Customer, (string) Str::uuid());
    $service->credit($source, 50_000, TransactionType::Topup, null, 'seed');

    $destinationId = (string) Str::uuid();

    // Fail *inside* the same boundary a transfer uses, after a debit has been
    // written. Before M23 the debit lived in its own committed transaction and
    // would have survived this.
    try {
        app(TransactionManager::class)->atomic(function () use ($service, $source, $destinationId): void {
            $service->debit($source, 20_000, TransactionType::Transfer, $destinationId, 'leg one');

            throw new RuntimeException('provider exploded between the two legs');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect((int) WalletModel::query()->whereKey($source->id())->value('balance_minor'))->toBe(50_000)
        ->and(WalletTransactionModel::query()->where('wallet_id', $source->id())->count())->toBe(1);
});

it('moves funds between two wallets with the total conserved', function (): void {
    $service = walletService();
    $aUser = (string) Str::uuid();
    $bUser = (string) Str::uuid();

    $a = $service->getOrOpen(WalletOwnerType::Customer, $aUser);
    $service->credit($a, 30_000, TransactionType::Topup, null, 'seed');
    $service->getOrOpen(WalletOwnerType::Customer, $bUser);

    $service->transfer(WalletOwnerType::Customer, $aUser, WalletOwnerType::Customer, $bUser, 12_000, 'gift');

    $balances = WalletModel::query()->pluck('balance_minor', 'owner_id');

    expect((int) $balances[$aUser])->toBe(18_000)
        ->and((int) $balances[$bUser])->toBe(12_000)
        ->and(array_sum($balances->map(fn ($v): int => (int) $v)->all()))->toBe(30_000);
});

it('refuses a transfer larger than the balance and leaves both wallets untouched', function (): void {
    $service = walletService();
    $aUser = (string) Str::uuid();
    $bUser = (string) Str::uuid();

    $a = $service->getOrOpen(WalletOwnerType::Customer, $aUser);
    $service->credit($a, 5_000, TransactionType::Topup, null, 'seed');
    $service->getOrOpen(WalletOwnerType::Customer, $bUser);

    expect(fn () => $service->transfer(WalletOwnerType::Customer, $aUser, WalletOwnerType::Customer, $bUser, 9_000, null))
        ->toThrow(PaymentsInvalidState::class);

    $balances = WalletModel::query()->pluck('balance_minor', 'owner_id');
    expect((int) $balances[$aUser])->toBe(5_000)
        ->and((int) $balances[$bUser])->toBe(0);
});

it('rejects a write made from a stale copy of the wallet (lost-update detection)', function (): void {
    $wallets = app(WalletRepository::class);
    $service = walletService();

    $userId = (string) Str::uuid();
    $wallet = $service->getOrOpen(WalletOwnerType::Customer, $userId);
    $service->credit($wallet, 10_000, TransactionType::Topup, null, 'seed');

    // Two aggregates loaded at the same version — the shape of a lost update.
    $first = $wallets->findById($wallet->id());
    $second = $wallets->findById($wallet->id());

    $first->credit(new Money(1_000, 'NGN'), TransactionType::Topup, null, 'first', $wallets->nextTransactionId(), new DateTimeImmutable());
    $wallets->save($first);

    $second->credit(new Money(7_000, 'NGN'), TransactionType::Topup, null, 'second', $wallets->nextTransactionId(), new DateTimeImmutable());

    // The second writer must lose loudly rather than overwrite the first.
    expect(fn () => $wallets->save($second))->toThrow(ConcurrencyConflict::class);

    expect((int) WalletModel::query()->whereKey($wallet->id())->value('balance_minor'))->toBe(11_000);
});

it('never lets a debit push the balance below zero', function (): void {
    $service = walletService();
    $wallet = $service->getOrOpen(WalletOwnerType::Customer, (string) Str::uuid());
    $service->credit($wallet, 1_000, TransactionType::Topup, null, 'seed');

    expect(fn () => $service->debit($wallet, 1_001, TransactionType::Payment, null, 'too much'))
        ->toThrow(PaymentsInvalidState::class);

    expect((int) WalletModel::query()->whereKey($wallet->id())->value('balance_minor'))->toBe(1_000);
});
