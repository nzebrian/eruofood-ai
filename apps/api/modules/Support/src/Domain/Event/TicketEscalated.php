<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a ticket is escalated (manually or by the SLA scanner). */
final readonly class TicketEscalated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $ticketId,
        public string $ref,
        public string $priority,
        public string $reason,
        public ?string $assigneeId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'support.ticket_escalated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
