<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A subject passed verification.
 *
 * Consuming contexts subscribe to this to update their own eligibility
 * projection — Marketplace for vendors and riders, Commerce for stores,
 * Identity for the account's verification level. Verification never writes to
 * their tables; this event is the only coupling.
 */
final readonly class SubjectVerified implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $subjectType,
        public string $subjectId,
        public string $caseType,
        public string $level,
        public ?string $expiresAt,
        /** Who to tell. Null when the case has no reachable account. */
        public ?string $contactUserId = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.subject_verified';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
