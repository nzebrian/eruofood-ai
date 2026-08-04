<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\LedgerEntryType;

/**
 * One immutable movement on a member's points ledger. `points` is signed —
 * positive for an earn or an upward adjustment, negative for a redemption,
 * expiry or downward adjustment — and `balanceAfter` is the running balance once
 * this entry is applied. `expiresAt` is set only on earn entries that the expiry
 * policy can later sweep.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $id,
        public string $accountId,
        public LedgerEntryType $type,
        public int $points,
        public string $reason,
        public ?string $reference,
        public int $balanceAfter,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    /** Whether this earn entry is still live (unexpired) at the given moment. */
    public function isExpirable(DateTimeImmutable $now): bool
    {
        return $this->type === LedgerEntryType::Earn
            && $this->points > 0
            && $this->expiresAt !== null
            && $this->expiresAt <= $now;
    }
}
