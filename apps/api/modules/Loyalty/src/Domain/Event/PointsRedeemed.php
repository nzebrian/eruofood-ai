<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a member spends points on a reward. */
final readonly class PointsRedeemed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public int $points,
        public int $balance,
        public string $rewardId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.points_redeemed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
