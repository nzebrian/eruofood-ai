<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A rider accepted, and the delivery is theirs.
 *
 * The event the rest of the platform waits for: notifications tell the rider
 * and the customer, Marketplace links the delivery to the rider, the Global
 * Command Centre updates. Published inside the assignment transaction, so a
 * subscriber can never see an assignment that was rolled back.
 *
 * Carries ids only. Anything needing the rider\'s position or the customer\'s
 * address reads it through the service that authorises such a read; an event
 * fans out to subscribers that have no such check.
 */
final readonly class DeliveryAssigned implements DomainEvent
{
    public function __construct(
        public string $assignmentId,
        public string $requestId,
        public string $deliveryId,
        public string $riderId,
        public ?string $vehicleId,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.delivery_assigned';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
