<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A route was computed.
 *
 * Consumed by Analytics (provider cost and cache effectiveness) and by the
 * Admin surface that reports mapping health. Carries no coordinates: an
 * event that fans out to several contexts is not the place for anybody's
 * origin and destination.
 */
final readonly class RouteCalculated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $provider,
        public string $source,
        public int $distanceMetres,
        public int $durationSeconds,
        public string $travelMode,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.route_calculated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
