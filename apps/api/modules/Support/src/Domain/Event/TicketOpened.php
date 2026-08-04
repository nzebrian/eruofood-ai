<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a customer opens a support ticket. */
final readonly class TicketOpened implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $ticketId,
        public string $ref,
        public string $requesterId,
        public string $subject,
        public string $priority,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'support.ticket_opened';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
