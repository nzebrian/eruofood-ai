<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when an administrator publishes a system announcement/broadcast. The
 * Notifications context listens and fans it out over its channels; Admin owns
 * only the authoring/audit, never the delivery.
 */
final readonly class BroadcastRequested implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    /**
     * @param list<string> $audience
     */
    public function __construct(
        public string $announcementId,
        public string $title,
        public string $body,
        public array $audience,
        public string $actorId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.broadcast_requested';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
