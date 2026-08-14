<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A rider reported a new position.
 *
 * Published now so that M26 dispatch and M27 live tracking have a seam to
 * subscribe to when they arrive; M25 itself has no consumer beyond the admin
 * health view. Deliberately carries the rider id and a timestamp but **not**
 * the coordinates — a rider's position is exactly the kind of data that should
 * not be broadcast to every listener on the bus. Anything that needs the point
 * reads it, under authorisation, from the repository.
 */
final readonly class RiderLocationUpdated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $riderId,
        public string $recordedAt,
        public ?float $accuracyMetres,
        public string $source,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.rider_location_updated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
