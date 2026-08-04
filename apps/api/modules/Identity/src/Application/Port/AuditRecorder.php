<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\UserId;

/**
 * Appends immutable audit-trail entries for security-relevant actions
 * (MASTER_PLAN.md §7.4). Extracted to the Audit context in a later phase.
 */
interface AuditRecorder
{
    /**
     * @param array<string, mixed> $context
     */
    public function record(string $action, ?UserId $actor, array $context = []): void;
}
