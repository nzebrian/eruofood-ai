<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A subject has handed their verification to a provider.
 *
 * The "we've got it" moment. Worth telling somebody about because the next step
 * is a wait of unknown length, and silence after submitting documents reads as
 * failure.
 *
 * Carries only what a message needs to say *that* something was submitted:
 * which case, which kind of subject, and who to tell. No document details, no
 * provider session reference — anything a subject needs to actually see lives
 * behind a login.
 */
final readonly class VerificationSubmitted implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $subjectType,
        public string $caseType,
        public ?string $contactUserId,
        public ?string $businessKind = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.submitted';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
