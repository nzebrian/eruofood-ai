<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Emitted when a user creates or updates their health profile. */
final readonly class HealthProfileUpdated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public string $userId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'nutrition.health_profile_updated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
