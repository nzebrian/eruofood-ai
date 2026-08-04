<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a vendor/driver settlement run completes and funds are paid out. */
final readonly class SettlementCompleted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $settlementId,
        public string $payeeType,
        public string $payeeId,
        public int $netMinor,
        public string $currency,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.settlement_completed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
