<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Security;

use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Verification\Application\Port\SensitiveAccessLogger;
use EruoFood\Verification\Domain\Event\SensitiveDataAccessed;

/**
 * Turns every read of regulated identity data into an immutable audit event.
 *
 * Publishes {@see SensitiveDataAccessed} rather than writing the audit row
 * itself: the Admin context owns the audit log, and Verification reaching into
 * its table would break the same module boundary the rest of this design
 * respects.
 *
 * The correlation id joins an access record to the exact request that made it.
 * It is taken from {@see CorrelationContext}, which is the *server-generated*
 * id — never the caller's `X-Request-Id`, even when one was supplied and echoed
 * back. This used to read the raw header, which meant two things: on the main
 * API nothing set that header so the field was always null, and where a caller
 * did send one they were choosing the correlation id on their own regulated-data
 * access — able to point it at an unrelated request, or reuse one id so several
 * accesses looked like a single event.
 *
 * Nothing here touches the application log. The audit trail carries *that*
 * someone looked and on what authority; it never carries what they saw.
 */
final readonly class AuditingSensitiveAccessLogger implements SensitiveAccessLogger
{
    public function __construct(
        private EventBus $events,
    ) {
    }

    public function record(
        string $caseId,
        string $actorId,
        string $permission,
        string $action,
        string $result,
        ?string $reason = null,
        ?string $correlationId = null,
    ): void {
        $this->events->publish(new SensitiveDataAccessed(
            caseId: $caseId,
            actorId: $actorId,
            permission: $permission,
            action: $action,
            reason: $reason,
            result: $result,
            correlationId: $correlationId ?? CorrelationContext::forAudit(),
        ));
    }
}
