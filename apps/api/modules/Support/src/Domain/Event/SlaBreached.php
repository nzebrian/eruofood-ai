<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a ticket breaches its resolution SLA — cues escalation alerts. */
final readonly class SlaBreached implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $ticketId,
        public string $ref,
        public string $stage,
        public ?string $assigneeId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'support.sla_breached';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
