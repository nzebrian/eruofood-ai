<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A subject's verification lapsed — aged out, or reverification demanded.
 *
 * Consumers must treat this exactly like a loss of verification: a rider whose
 * licence expired is no more eligible than one who never verified.
 */
final readonly class SubjectExpired implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $subjectType,
        public string $subjectId,
        public string $caseType,
        /** Who to tell. Null when the case has no reachable account. */
        public ?string $contactUserId = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.subject_expired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
