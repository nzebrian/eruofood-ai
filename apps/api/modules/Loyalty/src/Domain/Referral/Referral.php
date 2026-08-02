<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Referral;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\ReferralStatus;
use EruoFood\Loyalty\Domain\Exception\LoyaltyConflict;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;

/**
 * A referral attribution: a referee has entered a referrer's code. It starts
 * pending and qualifies when the referee triggers the configured qualifying
 * event (their first order), at which point both sides are awarded points. Self-
 * referral is rejected at attribution, and a referee can be attributed only once
 * (enforced here and by a unique index).
 */
final class Referral
{
    private function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly string $referrerUserId,
        private readonly string $refereeUserId,
        private ReferralStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $qualifiedAt,
    ) {
    }

    public static function attribute(
        string $id,
        string $code,
        string $referrerUserId,
        string $refereeUserId,
        DateTimeImmutable $now,
    ): self {
        if ($referrerUserId === $refereeUserId) {
            throw new LoyaltyConflict('You cannot refer yourself.');
        }

        return new self($id, $code, $referrerUserId, $refereeUserId, ReferralStatus::Pending, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $code,
        string $referrerUserId,
        string $refereeUserId,
        ReferralStatus $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $qualifiedAt,
    ): self {
        return new self($id, $code, $referrerUserId, $refereeUserId, $status, $createdAt, $qualifiedAt);
    }

    public function qualify(DateTimeImmutable $now): void
    {
        if ($this->status !== ReferralStatus::Pending) {
            throw new LoyaltyInvalidState('This referral has already qualified.');
        }
        $this->status = ReferralStatus::Qualified;
        $this->qualifiedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function referrerUserId(): string
    {
        return $this->referrerUserId;
    }

    public function refereeUserId(): string
    {
        return $this->refereeUserId;
    }

    public function status(): ReferralStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function qualifiedAt(): ?DateTimeImmutable
    {
        return $this->qualifiedAt;
    }
}
