<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a client exhausts its daily/monthly quota. */
final readonly class QuotaExceeded implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $applicationId,
        public string $period,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'publicapi.quota_exceeded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
