<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/**
 * The lifecycle of an order. Transitions are guarded by the Order aggregate so
 * an order can only move forward through valid states (or be cancelled early).
 */
enum OrderStatus: string
{
    case Pending = 'pending';       // placed, awaiting vendor confirmation
    case Confirmed = 'confirmed';   // vendor accepted
    case Preparing = 'preparing';
    case Ready = 'ready';           // ready for pickup/dispatch
    case Dispatched = 'dispatched'; // handed to rider / out for delivery
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** Whether this status may transition into $next. */
    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Cancelled) {
            return ! in_array($this, [self::Delivered, self::Cancelled], true);
        }

        return match ($this) {
            self::Pending => $next === self::Confirmed,
            self::Confirmed => $next === self::Preparing,
            self::Preparing => $next === self::Ready,
            self::Ready => $next === self::Dispatched,
            self::Dispatched => $next === self::Delivered,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }
}
