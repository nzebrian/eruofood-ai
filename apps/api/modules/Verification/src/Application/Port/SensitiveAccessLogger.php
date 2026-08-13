<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

/**
 * Records every read of regulated identity data.
 *
 * Called on the success path and the denial path alike. Two reasons: a refused
 * attempt to view someone's documents is exactly the signal a security review
 * wants, and logging only successes would let an attacker probe quietly.
 *
 * This is not application logging. It writes an immutable audit entry; ordinary
 * logs never receive the data itself.
 */
interface SensitiveAccessLogger
{
    /**
     * @param string $result 'granted' or 'denied'
     * @param string|null $reason why the actor says they need it, where captured
     * @param string|null $correlationId the request id, so an access ties back to a trace
     */
    public function record(
        string $caseId,
        string $actorId,
        string $permission,
        string $action,
        string $result,
        ?string $reason = null,
        ?string $correlationId = null,
    ): void;
}
