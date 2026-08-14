<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A human confirmed a location is correct.
 *
 * Consumed by Marketplace and Commerce, which treat a confirmed trading address
 * as one of the conditions for a merchant being fully operational.
 */
final readonly class LocationVerified implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $locationId,
        public string $actorId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.location_verified';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
