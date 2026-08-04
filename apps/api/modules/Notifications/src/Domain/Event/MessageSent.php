<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a chat message is sent (drives real-time push to participants). */
final readonly class MessageSent implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $messageId,
        public string $conversationId,
        public string $senderId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'notifications.message_sent';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
