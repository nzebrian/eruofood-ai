<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * An account confirmed a phone number.
 *
 * The lower rung of progressive verification. Identity subscribes to raise the
 * account's `verification_level`, which is what step-up checks read.
 *
 * The number itself is not carried: consumers need to know the account reached
 * this level, not what the number is, and putting it on an event that fans out
 * to several contexts and the audit log would spread the PII rather than
 * contain it.
 */
final readonly class PhoneVerified implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $userId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.phone_verified';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
