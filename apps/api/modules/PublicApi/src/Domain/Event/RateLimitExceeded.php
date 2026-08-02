<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a client hits its rate limit — feeds abuse monitoring. */
final readonly class RateLimitExceeded implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $applicationId,
        public string $route,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'publicapi.rate_limited';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
