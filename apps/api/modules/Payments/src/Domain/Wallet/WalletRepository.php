<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Wallet;

use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Wallet} aggregate and its statement. */
interface WalletRepository
{
    public function nextIdentity(): string;

    public function nextTransactionId(): string;

    public function findById(string $id): ?Wallet;

    public function findForOwner(WalletOwnerType $ownerType, string $ownerId): ?Wallet;

    /**
     * Read the wallet and hold an exclusive row lock until the surrounding
     * transaction ends.
     *
     * Any read whose value is about to be used in a write — checking a balance
     * before debiting it — must use this rather than {@see findById()}. Without
     * the lock two concurrent debits both read the same balance, both find it
     * sufficient, and both write; one movement is lost and the wallet is
     * overdrawn.
     *
     * Must be called inside a
     * {@see \EruoFood\Shared\Domain\TransactionManager::atomic()} boundary — a
     * lock taken outside a transaction is released immediately and protects
     * nothing.
     */
    public function findByIdForUpdate(string $id): ?Wallet;

    /** Locking counterpart of {@see findForOwner()}. See {@see findByIdForUpdate()}. */
    public function findForOwnerForUpdate(WalletOwnerType $ownerType, string $ownerId): ?Wallet;

    /**
     * Persist the wallet and append any new statement transactions atomically.
     *
     * @throws \EruoFood\Shared\Domain\Exception\ConcurrencyConflict when the row
     *                                                               changed since the aggregate was loaded (lost-update detection)
     */
    public function save(Wallet $wallet): void;

    /** @return Paginated<WalletTransaction> */
    public function statement(string $walletId, int $page, int $perPage): Paginated;
}
