<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when an administrator reactivates a previously suspended user. */
final readonly class AdminUserReinstated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
        public string $actorId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.user_reinstated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
