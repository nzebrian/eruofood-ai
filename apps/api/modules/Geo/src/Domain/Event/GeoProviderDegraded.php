<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A provider crossed its failure threshold and the circuit opened.
 *
 * Consumed by the Admin/Global Command Centre health surface and by the M24
 * Notification Service as an operational alert. This is the event that says
 * "delivery quoting is about to start refusing", which is worth knowing before
 * customers find out.
 */
final readonly class GeoProviderDegraded implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $provider,
        public string $capability,
        public int $consecutiveFailures,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.provider_degraded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
