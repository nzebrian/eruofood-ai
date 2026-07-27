<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * The lifecycle of a payment. Transitions are guarded by the Payment aggregate:
 * a payment moves forward through initialization and capture, or fails/cancels,
 * and may later be (partially) refunded.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';       // created, awaiting provider initialization
    case Processing = 'processing'; // initialized at the provider, awaiting confirmation
    case Succeeded = 'succeeded';   // captured
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';                 // fully refunded
    case PartiallyRefunded = 'partially_refunded';

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
