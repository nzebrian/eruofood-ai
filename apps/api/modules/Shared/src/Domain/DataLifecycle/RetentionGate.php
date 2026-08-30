<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

use EruoFood\Shared\Domain\Flag\FlagEvaluator;

/**
 * The single switch under every unattended retention run.
 *
 * ## Why the gate is on the schedule and not on the command
 *
 * `RetentionRegistry` says what may be deleted and when. It has never said
 * *whether anything is allowed to run unattended*, and those are different
 * questions: deletion is the one operation on this platform that nobody can
 * undo — {@see DeletionMode::isReversible()} returns true only for `Archive`.
 *
 * The flag's own declaration is explicit about the scope it governs:
 * "**Scheduled** deletion and anonymisation of data past its declared retention
 * period", with a rollout strategy of "dry-run reporting counts per category for
 * a full cycle before the first destructive run". So this gates the scheduler.
 *
 * An operator typing `php artisan shared:purge-idempotency-keys` is already
 * making a deliberate, attributable decision at a specific database; gating that
 * as well would mean a flag has to be flipped before a dry run could even be
 * read, which is precisely backwards — the dry run is the thing that is supposed
 * to happen first.
 *
 * ## Two independent locks, deliberately
 *
 * Every retention task also ships `enabled: false`. Either lock alone stops an
 * unattended purge, and both are off. That redundancy is the point: a task
 * accidentally flipped to `enabled: true` still does nothing while the flag is
 * off, and turning the flag on still runs nothing while every task is disabled.
 */
final readonly class RetentionGate
{
    /**
     * Declared in `SharedServiceProvider` with `safeDefault: false`, and left
     * unset in `config/flags.php`, so it resolves to false unless somebody sets
     * `FLAG_LIFECYCLE_RETENTION_PURGE` on purpose.
     */
    public const string FLAG = 'lifecycle.retention_purge';

    public function __construct(private FlagEvaluator $flags)
    {
    }

    /** Whether an unattended, destructive retention run may be scheduled at all. */
    public function allowsScheduledPurge(): bool
    {
        return $this->flags->isEnabled(self::FLAG);
    }
}
