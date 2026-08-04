<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Reward;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\RedemptionStatus;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;

/**
 * A member's redemption of a reward — the issued voucher. It records the points
 * spent and a unique code the consuming context (Payments/Commerce) reads to
 * apply the benefit; Loyalty never applies the discount itself. It can be
 * fulfilled (benefit applied) or cancelled (points refunded, stock returned).
 */
final class Redemption
{
    private function __construct(
        private readonly string $id,
        private readonly string $rewardId,
        private readonly string $userId,
        private readonly string $code,
        private readonly int $pointsSpent,
        private readonly string $benefitType,
        private readonly int $benefitValue,
        private RedemptionStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function issue(
        string $id,
        string $rewardId,
        string $userId,
        string $code,
        int $pointsSpent,
        string $benefitType,
        int $benefitValue,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $rewardId, $userId, $code, $pointsSpent, $benefitType, $benefitValue, RedemptionStatus::Issued, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $rewardId,
        string $userId,
        string $code,
        int $pointsSpent,
        string $benefitType,
        int $benefitValue,
        RedemptionStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $rewardId, $userId, $code, $pointsSpent, $benefitType, $benefitValue, $status, $createdAt, $updatedAt);
    }

    public function fulfill(DateTimeImmutable $now): void
    {
        if ($this->status !== RedemptionStatus::Issued) {
            throw new LoyaltyInvalidState('Only an issued redemption can be fulfilled.');
        }
        $this->status = RedemptionStatus::Fulfilled;
        $this->updatedAt = $now;
    }

    public function cancel(DateTimeImmutable $now): void
    {
        if ($this->status !== RedemptionStatus::Issued) {
            throw new LoyaltyInvalidState('Only an issued redemption can be cancelled.');
        }
        $this->status = RedemptionStatus::Cancelled;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function rewardId(): string
    {
        return $this->rewardId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function pointsSpent(): int
    {
        return $this->pointsSpent;
    }

    public function benefitType(): string
    {
        return $this->benefitType;
    }

    public function benefitValue(): int
    {
        return $this->benefitValue;
    }

    public function status(): RedemptionStatus
    {
        return $this->status;
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
