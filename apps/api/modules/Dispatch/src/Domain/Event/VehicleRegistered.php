<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A rider registered a vehicle. It is not usable yet.
 *
 * Published so the verification queue and operator alerting have a seam,
 * without Dispatch knowing anything about either. Carries no registration
 * number: a plate identifies a vehicle to the state, and there is no reason to
 * put one on a bus every listener can read.
 */
final readonly class VehicleRegistered implements DomainEvent
{
    public function __construct(
        public string $vehicleId,
        public string $riderId,
        public string $vehicleType,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.vehicle_registered';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
