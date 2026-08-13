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
     * Read the account holding an exclusive row lock until the surrounding
     * transaction ends.
     *
     * Points are spendable value, so a balance that is about to be debited must
     * be read this way. Two concurrent redemptions that both read an unlocked
     * balance both find it sufficient, and the member spends the same points
     * twice.
     */
    public function findByUserForUpdate(string $userId): ?LoyaltyAccount;

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
