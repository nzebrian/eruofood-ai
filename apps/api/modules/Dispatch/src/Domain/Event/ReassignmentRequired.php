<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * An assigned rider is out and the delivery needs somebody else.
 *
 * Distinct from a plain failure: there was a rider, and there no longer is.
 * The customer has already been told a rider was coming, so this is the event
 * that has to reach them.
 */
final readonly class ReassignmentRequired implements DomainEvent
{
    public function __construct(
        public string $assignmentId,
        public string $deliveryId,
        public string $riderId,
        public string $reason,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.reassignment_required';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
