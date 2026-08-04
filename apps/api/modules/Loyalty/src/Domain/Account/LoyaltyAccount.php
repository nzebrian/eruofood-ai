<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\LedgerEntryType;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;
use EruoFood\Loyalty\Domain\ValueObject\Points;

/**
 * A member's loyalty account — the aggregate root and the consistency boundary
 * for their points. It keeps a running `balance` and the `lifetimePoints` ever
 * earned (which drives the tier), and appends an immutable {@see LedgerEntry}
 * for every movement. The balance never goes negative: earning and adjusting up
 * add, redeeming/expiring/adjusting down subtract, and a spend larger than the
 * balance is rejected. New entries accumulated in memory are flushed by the
 * persistence layer via {@see releaseNewEntries()}.
 *
 * The tier is set by the tier projector (the single writer), never here.
 */
final class LoyaltyAccount
{
    /** @var list<LedgerEntry> */
    private array $newEntries = [];

    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private int $balance,
        private int $lifetimePoints,
        private string $tierKey,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(string $id, string $userId, string $initialTierKey, DateTimeImmutable $now): self
    {
        return new self($id, $userId, 0, 0, $initialTierKey, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        int $balance,
        int $lifetimePoints,
        string $tierKey,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $userId, $balance, $lifetimePoints, $tierKey, $createdAt, $updatedAt);
    }

    /** Award points. Grows both the balance and lifetime total (so it can raise the tier). */
    public function earn(Points $points, string $reason, ?string $reference, string $entryId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): LedgerEntry
    {
        $this->balance += $points->value;
        $this->lifetimePoints += $points->value;
        $this->updatedAt = $now;

        return $this->append($entryId, LedgerEntryType::Earn, $points->value, $reason, $reference, $now, $expiresAt);
    }

    /** Spend points on a reward. Rejected if the balance cannot cover it. */
    public function redeem(Points $points, string $reason, ?string $reference, string $entryId, DateTimeImmutable $now): LedgerEntry
    {
        if ($points->value > $this->balance) {
            throw new LoyaltyInvalidState('Insufficient points balance for this redemption.');
        }
        $this->balance -= $points->value;
        $this->updatedAt = $now;

        return $this->append($entryId, LedgerEntryType::Redeem, -$points->value, $reason, $reference, $now, null);
    }

    /** Sweep expired points off the balance (never below zero). Does not touch lifetime. */
    public function expire(int $amount, ?string $reference, string $entryId, DateTimeImmutable $now): LedgerEntry
    {
        $amount = min(max($amount, 0), $this->balance);
        if ($amount === 0) {
            throw new LoyaltyInvalidState('No points to expire.');
        }
        $this->balance -= $amount;
        $this->updatedAt = $now;

        return $this->append($entryId, LedgerEntryType::Expire, -$amount, 'expiry', $reference, $now, null);
    }

    /**
     * A manual admin correction. A positive delta also counts toward lifetime
     * (it can raise a tier); a negative delta cannot drop the balance below zero.
     */
    public function adjust(int $delta, string $reason, string $entryId, DateTimeImmutable $now): LedgerEntry
    {
        if ($delta === 0) {
            throw new LoyaltyInvalidState('An adjustment must be non-zero.');
        }
        if ($this->balance + $delta < 0) {
            throw new LoyaltyInvalidState('An adjustment cannot make the balance negative.');
        }
        $this->balance += $delta;
        if ($delta > 0) {
            $this->lifetimePoints += $delta;
        }
        $this->updatedAt = $now;

        return $this->append($entryId, LedgerEntryType::Adjust, $delta, $reason, null, $now, null);
    }

    /** Set by the tier projector only. Returns whether the tier actually changed. */
    public function assignTier(string $tierKey, DateTimeImmutable $now): bool
    {
        if ($this->tierKey === $tierKey) {
            return false;
        }
        $this->tierKey = $tierKey;
        $this->updatedAt = $now;

        return true;
    }

    private function append(string $entryId, LedgerEntryType $type, int $signedPoints, string $reason, ?string $reference, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt): LedgerEntry
    {
        $entry = new LedgerEntry($entryId, $this->id, $type, $signedPoints, $reason, $reference, $this->balance, $now, $expiresAt);
        $this->newEntries[] = $entry;

        return $entry;
    }

    /** @return list<LedgerEntry> */
    public function releaseNewEntries(): array
    {
        $entries = $this->newEntries;
        $this->newEntries = [];

        return $entries;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function lifetimePoints(): int
    {
        return $this->lifetimePoints;
    }

    public function tierKey(): string
    {
        return $this->tierKey;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
