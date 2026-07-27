<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a refund is completed (drives the order/return flow in other contexts). */
final readonly class RefundCompleted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $refundId,
        public string $paymentId,
        public ?string $orderId,
        public int $amountMinor,
        public string $currency,
        public bool $partial,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.refund_completed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
