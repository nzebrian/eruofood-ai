<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Security;

use EruoFood\Shared\Domain\EventBus;
use EruoFood\Verification\Application\Port\SensitiveAccessLogger;
use EruoFood\Verification\Domain\Event\SensitiveDataAccessed;
use Illuminate\Http\Request;

/**
 * Turns every read of regulated identity data into an immutable audit event.
 *
 * Publishes {@see SensitiveDataAccessed} rather than writing the audit row
 * itself: the Admin context owns the audit log, and Verification reaching into
 * its table would break the same module boundary the rest of this design
 * respects.
 *
 * The correlation id comes from the request's `X-Request-Id`, which the platform
 * already stamps for tracing — so an access record can be joined to the exact
 * request that made it.
 *
 * Nothing here touches the application log. The audit trail carries *that*
 * someone looked and on what authority; it never carries what they saw.
 */
final readonly class AuditingSensitiveAccessLogger implements SensitiveAccessLogger
{
    public function __construct(
        private EventBus $events,
        private ?Request $request = null,
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
            correlationId: $correlationId ?? $this->correlationId(),
        ));
    }

    private function correlationId(): ?string
    {
        $header = $this->request?->header('X-Request-Id');

        return is_string($header) && $header !== '' ? $header : null;
    }
}
