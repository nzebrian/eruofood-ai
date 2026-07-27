<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a payment is captured. Other contexts (e.g. Commerce/Marketplace) may listen to mark their order paid — via this published event, never a direct call. */
final readonly class PaymentSucceeded implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $paymentId,
        public ?string $orderId,
        public string $payerUserId,
        public int $amountMinor,
        public string $currency,
        public string $provider,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.payment_succeeded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
