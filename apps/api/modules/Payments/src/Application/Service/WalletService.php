<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Verification\Contracts\StepUpGuard;

/**
 * Wallets for every account type: balances, credits/debits, top-ups (credited
 * when a payment succeeds), withdrawals, transfers between wallets, statements,
 * and paying an order from a wallet balance. Every movement appends an immutable
 * statement transaction and, for credits, publishes a wallet event.
 *
 * Every balance change runs inside one {@see TransactionManager::atomic()}
 * boundary and reads its wallet with a row lock. Both matter, for different
 * failures: the boundary stops a two-legged move from half-committing, and the
 * lock stops two concurrent movements from reading the same balance and each
 * deciding it is sufficient.
 *
 * Domain events are published *after* the transaction commits. A subscriber must
 * never observe a balance that a rollback is about to undo.
 */
final readonly class WalletService
{
    public function __construct(
        private WalletRepository $wallets,
        private EventBus $events,
        private TransactionManager $transactions,
        private StepUpGuard $stepUp,
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

        return $this->transactions->atomic(function () use ($ownerType, $ownerId): Wallet {
            // Re-read under lock: another request may have opened this wallet
            // between our check above and this transaction.
            $existing = $this->wallets->findForOwnerForUpdate($ownerType, $ownerId);
            if ($existing !== null) {
                return $existing;
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
        });
    }

    public function getById(string $walletId): Wallet
    {
        return $this->wallets->findById($walletId) ?? throw PaymentsNotFound::of('wallet', $walletId);
    }

    /**
     * Credit a wallet. The aggregate passed in is only used to identify the
     * wallet — the balance is re-read under lock so the movement always applies
     * to the committed balance, never to a stale copy.
     */
    public function credit(Wallet $wallet, int $amountMinor, TransactionType $type, ?string $reference, ?string $description): Wallet
    {
        return $this->move($wallet->id(), $amountMinor, $type, $reference, $description, credit: true);
    }

    /** Debit a wallet. See {@see credit()} for the re-read-under-lock contract. */
    public function debit(Wallet $wallet, int $amountMinor, TransactionType $type, ?string $reference, ?string $description): Wallet
    {
        return $this->move($wallet->id(), $amountMinor, $type, $reference, $description, credit: false);
    }

    /**
     * Move funds between two wallets as one indivisible operation.
     *
     * Both legs share a single transaction, so the money can never exist in
     * neither wallet or in both. The wallets are locked in a fixed id order:
     * two opposing transfers (A→B and B→A) that grabbed their locks in arrival
     * order would deadlock, whereas a consistent order makes one simply wait.
     */
    public function transfer(WalletOwnerType $fromType, string $fromId, WalletOwnerType $toType, string $toId, int $amountMinor, ?string $note): void
    {
        if ($amountMinor <= 0) {
            throw new PaymentsInvalidState('Transfer amount must be positive.');
        }

        // M24: a large transfer out of a customer wallet is where a throwaway
        // account becomes expensive, so it is the point progressive
        // verification asks for more. Checked before anything is locked or
        // moved, and the threshold lives in configuration rather than here —
        // Payments asks whether this is gated, it does not decide.
        if ($fromType === WalletOwnerType::Customer) {
            $this->stepUp->assert('wallet.transfer', $fromId, $amountMinor);
        }

        $from = $this->getOrOpen($fromType, $fromId);
        $to = $this->getOrOpen($toType, $toId);

        if ($from->id() === $to->id()) {
            throw new PaymentsInvalidState('Cannot transfer to the same wallet.');
        }

        $events = $this->transactions->atomic(function () use ($from, $to, $amountMinor, $note): array {
            [$first, $second] = $this->lockPair($from->id(), $to->id());
            $source = $first->id() === $from->id() ? $first : $second;
            $destination = $first->id() === $to->id() ? $first : $second;

            $now = new DateTimeImmutable();
            $description = $note ?? 'Wallet transfer';

            $source->debit(
                new Money($amountMinor, $this->currency),
                TransactionType::Transfer,
                $destination->id(),
                $description,
                $this->wallets->nextTransactionId(),
                $now,
            );
            $destination->credit(
                new Money($amountMinor, $this->currency),
                TransactionType::Transfer,
                $source->id(),
                $description,
                $this->wallets->nextTransactionId(),
                $now,
            );

            $this->wallets->save($source);
            $this->wallets->save($destination);

            return [...$source->releaseEvents(), ...$destination->releaseEvents()];
        });

        $this->publish($events);
    }

    /**
     * Pay for an order from the customer's wallet, moving funds to the platform
     * escrow wallet. One transaction: the customer is never debited without the
     * matching escrow credit.
     */
    public function payFromWallet(string $customerUserId, int $amountMinor, ?string $orderId): void
    {
        if ($amountMinor <= 0) {
            throw new PaymentsInvalidState('Payment amount must be positive.');
        }

        $customer = $this->getOrOpen(WalletOwnerType::Customer, $customerUserId);
        $platform = $this->getOrOpen(WalletOwnerType::Platform, 'platform');

        $events = $this->transactions->atomic(function () use ($customer, $platform, $amountMinor, $orderId): array {
            [$first, $second] = $this->lockPair($customer->id(), $platform->id());
            $payer = $first->id() === $customer->id() ? $first : $second;
            $escrow = $first->id() === $platform->id() ? $first : $second;

            $now = new DateTimeImmutable();

            $payer->debit(
                new Money($amountMinor, $this->currency),
                TransactionType::Payment,
                $orderId,
                'Order payment',
                $this->wallets->nextTransactionId(),
                $now,
            );
            $escrow->credit(
                new Money($amountMinor, $this->currency),
                TransactionType::EscrowHold,
                $orderId,
                'Escrow hold',
                $this->wallets->nextTransactionId(),
                $now,
            );

            $this->wallets->save($payer);
            $this->wallets->save($escrow);

            return [...$payer->releaseEvents(), ...$escrow->releaseEvents()];
        });

        $this->publish($events);
    }

    /** @return Paginated<\EruoFood\Payments\Domain\Wallet\WalletTransaction> */
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

    /**
     * One locked, atomic balance movement.
     */
    private function move(
        string $walletId,
        int $amountMinor,
        TransactionType $type,
        ?string $reference,
        ?string $description,
        bool $credit,
    ): Wallet {
        [$wallet, $events] = $this->transactions->atomic(
            function () use ($walletId, $amountMinor, $type, $reference, $description, $credit): array {
                $locked = $this->wallets->findByIdForUpdate($walletId)
                    ?? throw PaymentsNotFound::of('wallet', $walletId);

                $amount = new Money($amountMinor, $this->currency);
                $txnId = $this->wallets->nextTransactionId();
                $now = new DateTimeImmutable();

                if ($credit) {
                    $locked->credit($amount, $type, $reference, $description, $txnId, $now);
                } else {
                    $locked->debit($amount, $type, $reference, $description, $txnId, $now);
                }

                $this->wallets->save($locked);

                return [$locked, $locked->releaseEvents()];
            },
        );

        $this->publish($events);

        return $wallet;
    }

    /**
     * Lock two wallets in a deterministic order to rule out deadlock.
     *
     * @return array{0: Wallet, 1: Wallet} locked in ascending id order
     */
    private function lockPair(string $walletIdA, string $walletIdB): array
    {
        $ids = [$walletIdA, $walletIdB];
        sort($ids);

        $first = $this->wallets->findByIdForUpdate($ids[0]) ?? throw PaymentsNotFound::of('wallet', $ids[0]);
        $second = $this->wallets->findByIdForUpdate($ids[1]) ?? throw PaymentsNotFound::of('wallet', $ids[1]);

        return [$first, $second];
    }

    /** @param list<\EruoFood\Shared\Domain\DomainEvent> $events */
    private function publish(array $events): void
    {
        foreach ($events as $event) {
            $this->events->publish($event);
        }
    }
}
