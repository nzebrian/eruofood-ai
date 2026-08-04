<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when the expiry sweep removes points from a member's balance. */
final readonly class PointsExpired implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public int $points,
        public int $balance,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.points_expired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
