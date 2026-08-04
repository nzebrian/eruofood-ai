<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Ledger;

use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Shared\Domain\Paginated;

/** Append-only persistence port for the double-entry {@see LedgerEntry} ledger. */
interface LedgerRepository
{
    public function nextIdentity(): string;

    /**
     * Post a balanced group of entries (their signed amounts must sum to zero)
     * in one transaction.
     *
     * @param list<LedgerEntry> $entries
     */
    public function post(array $entries): void;

    /** Net balance (minor units) of an account. */
    public function balanceOf(LedgerAccount $account): int;

    /** @return Paginated<LedgerEntry> */
    public function forAccount(LedgerAccount $account, int $page, int $perPage): Paginated;

    /** @return list<LedgerEntry> */
    public function forCorrelation(string $correlationId): array;
}
