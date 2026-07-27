<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a customer places a commerce order at checkout. */
final readonly class OrderPlaced implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $orderId,
        public string $customerUserId,
        public int $totalMinor,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'commerce.order_placed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
