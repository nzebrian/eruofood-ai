<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Subscription;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SubscriptionStatus;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A recurring billing agreement — a plan charged to a user's saved method on a
 * fixed interval. The aggregate tracks the next billing date; a scheduler drives
 * charges through the same payment pipeline. Architecture-ready subscription
 * billing.
 */
final class Subscription
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $plan,
        private readonly Money $amount,
        private readonly string $interval, // weekly|monthly
        private SubscriptionStatus $status,
        private DateTimeImmutable $nextBillingAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function start(
        string $id,
        string $userId,
        string $plan,
        Money $amount,
        string $interval,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $plan, $amount, $interval, SubscriptionStatus::Active, self::advance($now, $interval), $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $plan,
        Money $amount,
        string $interval,
        SubscriptionStatus $status,
        DateTimeImmutable $nextBillingAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $plan, $amount, $interval, $status, $nextBillingAt, $createdAt);
    }

    /** Record a successful charge and roll the next billing date forward. */
    public function recordCharge(DateTimeImmutable $at): void
    {
        $this->status = SubscriptionStatus::Active;
        $this->nextBillingAt = self::advance($at, $this->interval);
    }

    public function markPastDue(): void
    {
        $this->status = SubscriptionStatus::PastDue;
    }

    public function cancel(): void
    {
        $this->status = SubscriptionStatus::Cancelled;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->status !== SubscriptionStatus::Cancelled && $this->nextBillingAt <= $now;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    private static function advance(DateTimeImmutable $from, string $interval): DateTimeImmutable
    {
        return $from->modify($interval === 'weekly' ? '+1 week' : '+1 month');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function plan(): string
    {
        return $this->plan;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function interval(): string
    {
        return $this->interval;
    }

    public function status(): SubscriptionStatus
    {
        return $this->status;
    }

    public function nextBillingAt(): DateTimeImmutable
    {
        return $this->nextBillingAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
