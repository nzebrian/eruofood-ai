<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Wallets for every account type: balances, credits/debits, top-ups (credited
 * when a payment succeeds), withdrawals, transfers between wallets, statements,
 * and paying an order from a wallet balance. Every movement appends an immutable
 * statement transaction and, for credits, publishes a wallet event.
 */
final readonly class WalletService
{
    public function __construct(
        private WalletRepository $wallets,
        private PaymentNotifier $notifier,
        private EventBus $events,
        private string $currency,
        private int $lowBalanceThreshold,
    ) {
    }

    public function getOrOpen(WalletOwnerType $ownerType, string $ownerId): Wallet
    {
        $wallet = $this->wallets->findForOwner($ownerType, $ownerId);
        if ($wallet !== null) {
            return $wallet;
        }
        $wallet = Wallet::open(
            $this->wallets->nextIdentity(),
            $ownerType,
            $ownerId,
            $this->currency,
            $this->lowBalanceThreshold,
            new DateTimeImmutable(),
        );
        $this->wallets->save($wallet);

        return $wallet;
    }

    public function getById(string $walletId): Wallet
    {
        return $this->wallets->findById($walletId) ?? throw PaymentsNotFound::of('wallet', $walletId);
    }

    public function credit(Wallet $wallet, int $amountMinor, TransactionType $type, ?string $reference, ?string $description): Wallet
    {
        $wallet->credit(
            new Money($amountMinor, $this->currency),
            $type,
            $reference,
            $description,
            $this->wallets->nextTransactionId(),
            new DateTimeImmutable(),
        );
        $this->persist($wallet);

        return $wallet;
    }

    public function debit(Wallet $wallet, int $amountMinor, TransactionType $type, ?string $reference, ?string $description): Wallet
    {
        $wallet->debit(
            new Money($amountMinor, $this->currency),
            $type,
            $reference,
            $description,
            $this->wallets->nextTransactionId(),
            new DateTimeImmutable(),
        );
        $this->persist($wallet);

        return $wallet;
    }

    /** Move funds between two wallets atomically at the application level. */
    public function transfer(WalletOwnerType $fromType, string $fromId, WalletOwnerType $toType, string $toId, int $amountMinor, ?string $note): void
    {
        if ($amountMinor <= 0) {
            throw new PaymentsInvalidState('Transfer amount must be positive.');
        }
        $from = $this->getOrOpen($fromType, $fromId);
        $to = $this->getOrOpen($toType, $toId);
        $this->debit($from, $amountMinor, TransactionType::Transfer, $to->id(), $note ?? 'Wallet transfer');
        $this->credit($to, $amountMinor, TransactionType::Transfer, $from->id(), $note ?? 'Wallet transfer');
    }

    /** Pay for an order from the customer's wallet, moving funds to the platform escrow wallet. */
    public function payFromWallet(string $customerUserId, int $amountMinor, ?string $orderId): void
    {
        $customer = $this->getOrOpen(WalletOwnerType::Customer, $customerUserId);
        $this->debit($customer, $amountMinor, TransactionType::Payment, $orderId, 'Order payment');
        $platform = $this->getOrOpen(WalletOwnerType::Platform, 'platform');
        $this->credit($platform, $amountMinor, TransactionType::EscrowHold, $orderId, 'Escrow hold');
    }

    public function statement(string $walletId, int $page, int $perPage): Paginated
    {
        return $this->wallets->statement($walletId, $page, $perPage);
    }

    public function assertOwner(Wallet $wallet, string $userId, bool $actorIsAdmin): void
    {
        if (! $actorIsAdmin && ! $wallet->isOwnedBy($userId)) {
            throw new PaymentsNotAuthorized();
        }
    }

    private function persist(Wallet $wallet): void
    {
        $this->wallets->save($wallet);
        foreach ($wallet->releaseEvents() as $event) {
            $this->events->publish($event);
        }
    }
}
