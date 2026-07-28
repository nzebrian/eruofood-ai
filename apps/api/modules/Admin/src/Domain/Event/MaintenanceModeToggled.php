<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when maintenance mode is turned on or off platform-wide. */
final readonly class MaintenanceModeToggled implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public bool $enabled,
        public ?string $message,
        public string $actorId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.maintenance_mode_toggled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
