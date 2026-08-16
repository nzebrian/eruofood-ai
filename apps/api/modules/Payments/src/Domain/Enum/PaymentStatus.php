<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The lifecycle of a payment. Transitions are guarded by the Payment aggregate:
 * a payment moves forward through initialization and capture, or fails/cancels,
 * and may later be (partially) refunded.
 */
enum PaymentStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';       // created, awaiting provider initialization
    case Processing = 'processing'; // initialized at the provider, awaiting confirmation
    case Succeeded = 'succeeded';   // captured
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';                 // fully refunded
    case PartiallyRefunded = 'partially_refunded';

    /**
     * The platform-wide phase, for clients that must not guess.
     *
     * The mapping that matters is `Processing` → `ServerPhase::Processing`,
     * which {@see ServerPhase::isSafelyRetryable()} refuses to retry. A payment
     * initialised at the provider may still succeed; an app that retried it
     * because it had not heard back is how a customer gets charged twice.
     *
     * A refunded payment is still a *confirmed* payment. The money moved and
     * then moved back — two settled facts, not an unsuccessful one — and the
     * refund has its own status with its own phase.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Processing => ServerPhase::Processing,
            self::Succeeded, self::Refunded, self::PartiallyRefunded => ServerPhase::Confirmed,
            self::Failed => ServerPhase::Failed,
            self::Cancelled => ServerPhase::Cancelled,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Succeeded, self::Failed, self::Cancelled], true),
            self::Processing => in_array($next, [self::Succeeded, self::Failed, self::Cancelled], true),
            self::Succeeded => in_array($next, [self::Refunded, self::PartiallyRefunded], true),
            self::PartiallyRefunded => in_array($next, [self::Refunded, self::PartiallyRefunded], true),
            default => false,
        };
    }

    public function isCaptured(): bool
    {
        return in_array($this, [self::Succeeded, self::PartiallyRefunded, self::Refunded], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Refunded], true);
    }
}
