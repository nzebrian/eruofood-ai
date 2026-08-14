<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * An address was resolved to a point.
 *
 * Consumed by Search (to refresh the geo fields on its index) and by
 * Marketplace/Commerce (to update their local location projections, so hot
 * paths read a column rather than calling across a context boundary).
 */
final readonly class LocationGeocoded implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $locationId,
        public float $latitude,
        public float $longitude,
        public ?string $countryCode,
        public string $precision,
        public string $provider,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.location_geocoded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
