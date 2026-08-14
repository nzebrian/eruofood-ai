<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A location could not be resolved, or was disputed.
 *
 * Consumed by the Admin audit trail and by M24's Notification Service, which
 * asks the merchant to correct their address. The reason is a short internal
 * code, never the address text — an address in a notification payload would put
 * somebody's home into an inbox and an event log.
 */
final readonly class LocationVerificationFailed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $locationId,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'geo.location_verification_failed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
