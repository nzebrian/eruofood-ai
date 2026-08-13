<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\VerificationCase;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * One immutable entry in a case's history.
 *
 * Written for every transition without exception — including provider-driven
 * and system-driven ones — so the question "why is this rider verified?" always
 * has an answer naming who decided, when, and on what grounds. Persisted to an
 * append-only table protected by a database trigger.
 */
final readonly class StatusChange
{
    public function __construct(
        public string $caseId,
        public VerificationStatus $from,
        public VerificationStatus $to,
        public ActorType $actorType,
        public ?string $actorId,
        public ?string $reasonCode,
        public string $note,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
