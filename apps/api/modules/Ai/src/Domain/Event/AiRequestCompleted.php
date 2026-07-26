<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Event;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Emitted after a successful AI generation, carrying just enough for downstream
 * listeners (analytics, notifications) without coupling to the AI internals.
 */
final readonly class AiRequestCompleted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public AiFeature $feature,
        public string $provider,
        public string $model,
        public int $totalTokens,
        public bool $cached,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'ai.request_completed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
