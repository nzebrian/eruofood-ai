<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a member earns points — cues a "you earned points" notification. */
final readonly class PointsEarned implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public int $points,
        public int $balance,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.points_earned';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
