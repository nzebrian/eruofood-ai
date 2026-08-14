<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A route could not be computed.
 *
 * Consumed by the Admin health surface and, when sustained, by the M24
 * Notification Service as an operational alert. This is the signal that
 * delivery quoting is degrading, which matters well before anybody notices
 * orders failing.
 */
final readonly class RouteCalculationFailed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $provider,
        public string $reason,
        public string $travelMode,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.route_calculation_failed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
