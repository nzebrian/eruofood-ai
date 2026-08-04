<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a customer submits a CSAT score for a resolved ticket. */
final readonly class CsatSubmitted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $ticketId,
        public string $requesterId,
        public int $score,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'support.csat_submitted';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
