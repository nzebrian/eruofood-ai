<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A subject failed verification, with a classified reason.
 *
 * Carries whether the reason is retryable so a consumer can tell "try again"
 * from "this account cannot proceed" without interpreting reason codes itself.
 */
final readonly class SubjectRejected implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $subjectType,
        public string $subjectId,
        public string $caseType,
        public string $reasonCode,
        public bool $retryable,
        /** Who to tell. Null when the case has no reachable account. */
        public ?string $contactUserId = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.subject_rejected';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
