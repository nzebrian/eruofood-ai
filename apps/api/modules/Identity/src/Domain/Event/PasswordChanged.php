<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Event;

use DateTimeImmutable;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Shared\Domain\DomainEvent;

final readonly class PasswordChanged implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public UserId $userId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'identity.password_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
