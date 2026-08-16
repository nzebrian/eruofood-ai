<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The lifecycle of a commerce order. Transitions are guarded by the Order
 * aggregate so an order only moves forward through valid states (or is
 * cancelled before it ships).
 */
enum OrderStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';       // placed, awaiting payment/confirmation
    case Paid = 'paid';             // payment captured (architecture-ready hook)
    case Processing = 'processing'; // being picked/packed
    case Shipped = 'shipped';       // dispatched to the carrier
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';     // goods returned & refunded

    /** Whether this status may transition into $next. */
    /**
     * Where a retail order has got to.
     *
     * `Pending` means placed and awaiting payment — the customer's money has
     * not moved, so the honest coarse answer is that we have the order, not
     * that anything succeeded. `Paid` onwards is `Processing` until the goods
     * arrive: an order being picked is genuinely in progress, and treating it
     * as confirmed would let a client tell somebody their delivery is done.
     *
     * `Returned` is `Confirmed` for the same reason a refunded payment is: the
     * order completed and was then reversed. Two settled facts.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Paid, self::Processing, self::Shipped => ServerPhase::Processing,
            self::Delivered, self::Returned => ServerPhase::Confirmed,
            self::Cancelled => ServerPhase::Cancelled,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Cancelled) {
            return in_array($this, [self::Pending, self::Paid, self::Processing], true);
        }

        return match ($this) {
            self::Pending => $next === self::Paid,
            self::Paid => $next === self::Processing,
            self::Processing => $next === self::Shipped,
            self::Shipped => $next === self::Delivered,
            self::Delivered => $next === self::Returned,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }
}
