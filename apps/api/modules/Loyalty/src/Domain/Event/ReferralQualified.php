<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a referral qualifies and both referrer and referee are rewarded. */
final readonly class ReferralQualified implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $referralId,
        public string $referrerUserId,
        public string $refereeUserId,
        public int $referrerPoints,
        public int $refereePoints,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'loyalty.referral_qualified';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
