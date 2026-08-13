<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A subject who was verified must verify again.
 *
 * Distinct from expiry, and the distinction matters to the person receiving it:
 * expiry is routine and expected, while a demanded reverification usually
 * follows a concern or a policy change and needs to be acted on now.
 *
 * The *reason* is not carried. Where a reverification was prompted by a fraud
 * signal, spelling that out in an email tells whoever holds the mailbox — quite
 * possibly the person under suspicion — exactly what was noticed.
 */
final readonly class ReverificationRequired implements DomainEvent
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
        return 'verification.reverification_required';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
