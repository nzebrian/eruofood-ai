<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when an agent posts a public reply. Notifications reacts to inform the
 * customer — Support never sends the message itself.
 */
final readonly class TicketReplied implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $ticketId,
        public string $ref,
        public string $requesterId,
        public string $agentId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'support.ticket_replied';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
