<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a webhook delivery exhausts its retries. */
final readonly class WebhookFailed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $webhookId,
        public string $eventName,
        public int $attempts,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'publicapi.webhook_failed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
