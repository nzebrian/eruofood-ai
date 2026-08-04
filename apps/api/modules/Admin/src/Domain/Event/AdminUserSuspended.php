<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when an administrator suspends a platform user. Other contexts react
 * to this fact — Identity can revoke sessions, Notifications can inform the
 * user — without Admin ever calling into them directly.
 */
final readonly class AdminUserSuspended implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public string $actorId,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.user_suspended';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
