<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when an API key is revoked. */
final readonly class ApiKeyRevoked implements DomainEvent
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
        return 'publicapi.key_revoked';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
