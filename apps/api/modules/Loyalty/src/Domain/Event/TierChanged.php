<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when a member moves to a new tier — cues a congratulations message and
 * lets other contexts cache the member's tier (e.g. tier-based perks). Consumers
 * read the tier from this event; they never compute it.
 */
final readonly class TierChanged implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public string $fromTier,
        public string $toTier,
        public int $lifetimePoints,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.tier_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
