<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when a system configuration value or feature flag changes. Caches and
 * dependent contexts can invalidate/react; the change is also audit-logged.
 */
final readonly class SettingChanged implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $key,
        public string $group,
        public string $actorId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.setting_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
