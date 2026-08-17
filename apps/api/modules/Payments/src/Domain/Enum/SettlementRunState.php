<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The lifecycle of one settlement of one merchant over one window.
 *
 * ```
 *                   ┌──────────► Cancelled  (from Draft or Pending only)
 *                   │
 * Draft ──► Pending ──► Processing ──► Succeeded ──► Reversed
 *                           │  │
 *                           │  └──────► Unknown ──► Reconciling ──► Succeeded
 *                           │                            │          Failed
 *                           │                            └────────► ReconciliationRequired
 *                           └──────► Failed ──► Pending  (a fresh attempt)
 * ```
 *
 * Every state here earns its place by being a decision somebody has to make
 * differently.
 *
 * - **Draft** — computed, totalled, and reviewable, having moved nothing. This
 *   is the answer to a settlement amount arriving in a request body: the figure
 *   is derived first and approved second, and the two are separate acts.
 * - **Pending** — approved, waiting to be executed.
 * - **Processing** — accruals are reserved and a transfer is being attempted.
 * - **Unknown** — the transfer was dispatched and we never heard back. It is
 *   **not** a failure, has no transition to `Pending`, and is the single most
 *   important state in this enum: retrying an unknown transfer is how a
 *   merchant gets paid twice.
 * - **Reconciling** — the system is asking the provider what happened.
 * - **ReconciliationRequired** — the system asked and could not tell. A human's
 *   problem, and terminal until one acts.
 * - **Succeeded**, **Failed**, **Cancelled**, **Reversed** — outcomes.
 *
 * `Reversed` is reached only from `Succeeded`, and only by posting compensating
 * ledger entries. Nothing here rewrites a financial record.
 */
enum SettlementRunState: string implements ServerAuthoritative
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Unknown = 'unknown';
    case Reconciling = 'reconciling';
    case ReconciliationRequired = 'reconciliation_required';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Succeeded, self::Failed, self::Unknown],
            // The exclusion that matters. There is no route from Unknown to
            // Pending or Processing: the only way onward is to establish what
            // actually happened.
            self::Unknown => [self::Reconciling],
            self::Reconciling => [self::Succeeded, self::Failed, self::ReconciliationRequired],
            // Terminal until a human acts, and when they do it is through a
            // reconciliation case with a compensating posting, not a transition
            // back into the money-moving path.
            self::ReconciliationRequired => [],
            self::Succeeded => [self::Reversed],
            // A failed run may be attempted again. This is safe precisely
            // because Failed means the provider said no.
            self::Failed => [self::Pending],
            self::Cancelled, self::Reversed => [],
        };
    }

    /** Whether this run may still have money moved for it. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Whether a fresh money-moving attempt is permitted from here.
     *
     * The guard the execution path consults. Note that `Unknown` answers false
     * and `Failed` answers true, which is the entire distinction the
     * {@see GatewayOutcome} enum exists to preserve.
     */
    public function allowsNewAttempt(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    /** Whether reconciliation must run before anything else can. */
    public function requiresReconciliation(): bool
    {
        return $this === self::Unknown || $this === self::Reconciling;
    }

    /** Whether accruals reserved by this run are released back to the payable. */
    public function releasesAccruals(): bool
    {
        return $this === self::Cancelled || $this === self::Failed || $this === self::Reversed;
    }

    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Draft => ServerPhase::Draft,
            self::Pending => ServerPhase::Pending,
            // Unknown, Reconciling and ReconciliationRequired all project onto
            // Processing so that `ServerPhase::isSafelyRetryable()` — already
            // written, already tested, already refusing Processing — refuses
            // them too, rather than a second rule that has to be kept in step.
            self::Processing, self::Unknown, self::Reconciling, self::ReconciliationRequired => ServerPhase::Processing,
            self::Succeeded => ServerPhase::Confirmed,
            self::Failed, self::Reversed => ServerPhase::Failed,
            self::Cancelled => ServerPhase::Cancelled,
        };
    }
}
