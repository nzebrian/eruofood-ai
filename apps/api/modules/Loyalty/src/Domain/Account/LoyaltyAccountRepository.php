<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;

/**
 * Persistence port for the {@see LoyaltyAccount} aggregate and its ledger.
 * `save` persists the account and flushes its newly-appended entries atomically.
 */
interface LoyaltyAccountRepository
{
    public function nextIdentity(): string;

    public function nextEntryIdentity(): string;

    public function findById(string $id): ?LoyaltyAccount;

    public function findByUser(string $userId): ?LoyaltyAccount;

    /**
     * @return Paginated<LedgerEntry>
     */
    public function ledger(LedgerQuery $query): Paginated;

    /**
     * Earn entries that have reached their expiry and still contribute to a
     * balance — the input to the expiry sweep.
     *
     * @return list<LedgerEntry>
     */
    public function expirableEntries(DateTimeImmutable $now, int $limit): array;

    /** The total points already expired against a given earn entry (for partial sweeps). */
    public function expiredAgainst(string $earnEntryId): int;

    public function save(LoyaltyAccount $account): void;
}
