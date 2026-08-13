<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The provider has started work on a case.
 *
 * A progress signal rather than an outcome. Deliberately low priority: it is
 * reassurance, not news, and a platform that emails at every internal state
 * change teaches people to ignore its email.
 */
final readonly class VerificationProcessing implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $subjectType,
        public string $caseType,
        public ?string $contactUserId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.processing';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
