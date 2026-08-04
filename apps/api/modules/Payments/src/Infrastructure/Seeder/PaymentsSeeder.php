<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use Illuminate\Database\Seeder;

/**
 * Sample financial data: the platform (escrow/commission) wallet plus a demo
 * customer and vendor wallet with an opening top-up, so the wallet/statement
 * screens have data locally.
 */
final class PaymentsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var WalletRepository $wallets */
        $wallets = app(WalletRepository::class);
        $now = new DateTimeImmutable();

        $this->openWith($wallets, WalletOwnerType::Platform, 'platform', 0, $now);
        $this->openWith($wallets, WalletOwnerType::Customer, '00000000-0000-0000-0000-0000000000d1', 500000, $now);
        $this->openWith($wallets, WalletOwnerType::Vendor, '00000000-0000-0000-0000-0000000000d2', 1200000, $now);
    }

    private function openWith(WalletRepository $wallets, WalletOwnerType $type, string $ownerId, int $openingMinor, DateTimeImmutable $now): void
    {
        if ($wallets->findForOwner($type, $ownerId) !== null) {
            return;
        }
        $wallet = Wallet::open($wallets->nextIdentity(), $type, $ownerId, 'NGN', 50000, $now);
        if ($openingMinor > 0) {
            $wallet->credit(
                new \EruoFood\Shared\Domain\ValueObject\Money($openingMinor, 'NGN'),
                TransactionType::Topup,
                null,
                'Opening balance',
                $wallets->nextTransactionId(),
                $now,
            );
        }
        $wallets->save($wallet);
    }
}
