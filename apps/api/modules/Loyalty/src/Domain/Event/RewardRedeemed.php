<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when a member redeems a reward — carries the issued voucher so the
 * consuming context (Payments/Commerce) can apply the benefit. Loyalty never
 * applies a discount itself; it issues the voucher and publishes this event.
 */
final readonly class RewardRedeemed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $redemptionId,
        public string $userId,
        public string $rewardId,
        public string $code,
        public string $benefitType,
        public int $benefitValue,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.reward_redeemed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
