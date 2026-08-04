<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Event;

use DateTimeImmutable;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Shared\Domain\DomainEvent;

final readonly class TwoFactorEnabled implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public UserId $userId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'identity.two_factor_enabled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
