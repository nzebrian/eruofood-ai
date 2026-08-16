<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Reconciliation;

/**
 * What reconciliation found.
 *
 * Three answers, and the distinction between the first and the others is the
 * one that prevents double charges.
 */
enum ReconciliationOutcome: string
{
    /**
     * No record. Nothing happened; the client may send it again.
     *
     * Deliberately not merged with a failure. "We never received it" and "it
     * was declined" are both "no money moved", but only the first makes a
     * resend correct.
     */
    case NeverReceived = 'never_received';

    /** Received, still being worked on. Wait; do not resend. */
    case InProgress = 'in_progress';

    /** Finished, one way or the other. The phase says which. */
    case Settled = 'settled';

    public function explain(): string
    {
        return match ($this) {
            self::NeverReceived => 'We have no record of this request. Nothing took effect, so it is safe to send again.',
            self::InProgress => 'We received this and it is still being worked on. Wait for the outcome rather than sending it again.',
            self::Settled => 'This finished. The phase says whether it succeeded.',
        };
    }
}
