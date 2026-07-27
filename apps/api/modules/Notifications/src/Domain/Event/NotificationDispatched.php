<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a notification has been handed to a channel sender. */
final readonly class NotificationDispatched implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $notificationId,
        public string $userId,
        public string $channel,
        public string $category,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'notifications.dispatched';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
