<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * The life of a discrepancy.
 *
 * ```
 * Open ──► Investigating ──► ResolvedMatched
 *                        ├─► ResolvedAdjusted   (approver + compensating posting)
 *                        └─► Escalated
 * ```
 *
 * `ResolvedMatched` is the only closure the system may reach on its own, and
 * only when the provider and the platform turn out to agree — a case opened by
 * a transient outage, closed once the outage passes. Everything else needs a
 * person.
 *
 * `ResolvedAdjusted` needs two things the database checks for, not just the
 * code: a named approver and a compensating ledger posting. There is no way to
 * close a case by writing a note.
 */
enum ReconciliationState: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case ResolvedMatched = 'resolved_matched';
    case ResolvedAdjusted = 'resolved_adjusted';
    case Escalated = 'escalated';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Investigating, self::ResolvedMatched, self::Escalated],
            self::Investigating => [self::ResolvedMatched, self::ResolvedAdjusted, self::Escalated],
            // An escalated case can still be resolved; escalation raises
            // attention, it does not close the door.
            self::Escalated => [self::ResolvedMatched, self::ResolvedAdjusted],
            self::ResolvedMatched, self::ResolvedAdjusted => [],
        };
    }

    public function isResolved(): bool
    {
        return $this === self::ResolvedMatched || $this === self::ResolvedAdjusted;
    }

    /** Whether reaching this state requires an approver and a compensating entry. */
    public function requiresCompensation(): bool
    {
        return $this === self::ResolvedAdjusted;
    }
}
