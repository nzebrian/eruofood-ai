<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * An operator accepted or refused a vehicle's paperwork.
 *
 * The moment a rider's ability to earn changes, so it is the moment they should
 * be told — the notification subscriber listens here rather than the service
 * calling the notifier directly, which keeps Dispatch from depending on how
 * anybody is contacted.
 *
 * One event for both outcomes, with a flag, because every consumer cares about
 * both: a rider needs to hear either answer, and an operator dashboard counts
 * the pair.
 */
final readonly class VehicleVerificationDecided implements DomainEvent
{
    public function __construct(
        public string $vehicleId,
        public string $riderId,
        public bool $approved,
        public ?string $reason,
        private DateTimeImmutable $occurredAt,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.vehicle_verification_decided';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
