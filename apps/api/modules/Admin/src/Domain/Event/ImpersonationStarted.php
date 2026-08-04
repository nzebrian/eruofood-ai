<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when an admin begins acting as another user. Always audit-logged. */
final readonly class ImpersonationStarted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $impersonationId,
        public string $adminUserId,
        public string $targetUserId,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.impersonation_started';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
