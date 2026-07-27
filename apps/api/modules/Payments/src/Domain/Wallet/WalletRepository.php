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
     * Persist the wallet and append any new statement transactions atomically.
     */
    public function save(Wallet $wallet): void;

    /** @return Paginated<WalletTransaction> */
    public function statement(string $walletId, int $page, int $perPage): Paginated;
}
