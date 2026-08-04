<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a new API key is issued. */
final readonly class ApiKeyIssued implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $keyId,
        public string $applicationId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'publicapi.key_issued';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
