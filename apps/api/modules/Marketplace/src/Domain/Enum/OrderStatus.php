<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The lifecycle of an order. Transitions are guarded by the Order aggregate so
 * an order can only move forward through valid states (or be cancelled early).
 */
enum OrderStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';       // placed, awaiting vendor confirmation
    case Confirmed = 'confirmed';   // vendor accepted
    case Preparing = 'preparing';
    case Ready = 'ready';           // ready for pickup/dispatch
    case Dispatched = 'dispatched'; // handed to rider / out for delivery
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** Whether this status may transition into $next. */
    /**
     * Where a food order has got to.
     *
     * `Pending` is awaiting the vendor; everything from acceptance to handover
     * is `Processing`, because a kitchen that has started cooking cannot have
     * the order silently retried against it.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Confirmed, self::Preparing, self::Ready, self::Dispatched => ServerPhase::Processing,
            self::Delivered => ServerPhase::Confirmed,
            self::Cancelled => ServerPhase::Cancelled,
        };
    }

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
