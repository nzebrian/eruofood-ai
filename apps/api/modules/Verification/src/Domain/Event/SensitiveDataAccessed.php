<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Somebody read regulated identity data.
 *
 * Emitted on *every* successful PII read regardless of who made it — including
 * a SuperAdmin's. The point is not to prevent privileged access but to make it
 * visible: an audit that cannot show who looked at a rider's document is not an
 * audit. Consumed by the Admin context and written to the immutable audit log.
 */
final readonly class SensitiveDataAccessed implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $caseId,
        public string $actorId,
        public string $permission,
        public string $action,
        public ?string $reason,
        /** 'granted' or 'denied' — refusals are recorded too. */
        public string $result,
        /** The request id, so an access record joins back to its trace. */
        public ?string $correlationId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'verification.sensitive_data_accessed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
