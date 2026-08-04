<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised after each public API request — feeds usage analytics. */
final readonly class ApiRequestServed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $applicationId,
        public string $route,
        public string $method,
        public int $statusCode,
        public int $latencyMs,
        public string $version,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'publicapi.request_served';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
