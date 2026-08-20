<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * One attempt to move money to a merchant's bank account.
 *
 * ```
 * Created ──► Submitted ──► Confirmed
 *                 │  │
 *                 │  └─────► Unknown ──► Reconciling ──► Confirmed | Failed | ReconciliationRequired
 *                 └────────► Rejected
 * ```
 *
 * An attempt is never retried; a retry is a **new row**. That is why there is
 * no transition out of `Rejected`: the run creates another attempt with its own
 * idempotency key, and "how many times did we try to pay this merchant, and
 * what happened each time" stays answerable for ever.
 *
 * `Created` exists so the row is written **before** the provider is called. A
 * process that dies during the transfer leaves evidence that the attempt was
 * made — which is exactly the crash window that used to lose money silently.
 */
enum PayoutAttemptState: string implements ServerAuthoritative
{
    /** Row written; the provider has not been called yet. */
    case Created = 'created';

    /** The provider has been called and accepted the instruction. */
    case Submitted = 'submitted';

    /** The provider confirmed the money left. */
    case Confirmed = 'confirmed';

    /** The provider refused. Nothing moved; a new attempt is safe. */
    case Rejected = 'rejected';

    /** We do not know. Never retried directly. */
    case Unknown = 'unknown';

    /** Asking the provider what happened. */
    case Reconciling = 'reconciling';

    /** We asked and could not tell. A human's problem. */
    case ReconciliationRequired = 'reconciliation_required';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Created => [self::Submitted, self::Rejected, self::Unknown],
            self::Submitted => [self::Confirmed, self::Rejected, self::Unknown],
            self::Unknown => [self::Reconciling],
            self::Reconciling => [self::Confirmed, self::Rejected, self::ReconciliationRequired],
            self::Confirmed, self::Rejected, self::ReconciliationRequired => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Whether the run may create a further attempt after this one. */
    public function permitsAnotherAttempt(): bool
    {
        return $this === self::Rejected;
    }

    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Created => ServerPhase::Submitted,
            self::Submitted, self::Unknown, self::Reconciling, self::ReconciliationRequired => ServerPhase::Processing,
            self::Confirmed => ServerPhase::Confirmed,
            self::Rejected => ServerPhase::Failed,
        };
    }
}
