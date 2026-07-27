<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/**
 * The lifecycle of a commerce order. Transitions are guarded by the Order
 * aggregate so an order only moves forward through valid states (or is
 * cancelled before it ships).
 */
enum OrderStatus: string
{
    case Pending = 'pending';       // placed, awaiting payment/confirmation
    case Paid = 'paid';             // payment captured (architecture-ready hook)
    case Processing = 'processing'; // being picked/packed
    case Shipped = 'shipped';       // dispatched to the carrier
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';     // goods returned & refunded

    /** Whether this status may transition into $next. */
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
