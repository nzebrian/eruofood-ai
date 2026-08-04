<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Emitted when a customer places an order (drives vendor notification, etc.). */
final readonly class OrderPlaced implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $orderId,
        public string $vendorId,
        public string $customerUserId,
        public int $totalMinorUnits,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'marketplace.order_placed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
