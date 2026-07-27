<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a payment attempt fails. */
final readonly class PaymentFailed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $paymentId,
        public ?string $orderId,
        public string $payerUserId,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.payment_failed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
