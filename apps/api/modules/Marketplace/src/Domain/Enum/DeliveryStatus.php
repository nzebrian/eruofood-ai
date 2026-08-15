<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/**
 * The lifecycle of a delivery job.
 *
 * ## M26 extended this in place
 *
 * The pre-M26 lifecycle was `unassigned → assigned → picked_up → en_route →
 * delivered`, which could not express the half of the journey that happens
 * before the food is collected — a customer could not be told "your rider is on
 * the way to the restaurant" because the platform had no such state.
 *
 * M26 added `offered`, `en_route_pickup`, `arrived_pickup` and `in_transit`.
 * Marketplace's `Delivery` remains the operational delivery aggregate (M26
 * decision 1); Dispatch mirrors this, never the reverse.
 *
 * ## Two legacy aliases, kept on purpose
 *
 * `assigned` and `en_route` are the pre-M26 names for `accepted` and
 * `in_transit`. They are **not** removed and **not** rewritten: existing rows
 * hold those values, and a migration that rewrote live delivery rows to make an
 * enum tidier would be changing operational records for cosmetics. Both are
 * treated as occupying the same position in the journey as their modern
 * equivalents, so old rows continue to advance normally.
 *
 * New deliveries use the modern names. See {@see isLegacyAlias()}.
 */
enum DeliveryStatus: string
{
    case Unassigned = 'unassigned';

    /** A rider has been asked and has not answered yet. */
    case Offered = 'offered';

    /** A rider took the job. The pre-M26 name for this is `assigned`. */
    case Accepted = 'accepted';

    /** Pre-M26 name for {@see Accepted}. Retained so existing rows still work. */
    case Assigned = 'assigned';

    case EnRoutePickup = 'en_route_pickup';
    case ArrivedPickup = 'arrived_pickup';
    case PickedUp = 'picked_up';

    /** Carrying the food to the customer. The pre-M26 name for this is `en_route`. */
    case InTransit = 'in_transit';

    /** Pre-M26 name for {@see InTransit}. Retained so existing rows still work. */
    case EnRoute = 'en_route';

    case Delivered = 'delivered';
    case Failed = 'failed';

    /** Whether this is a pre-M26 name kept for compatibility. */
    public function isLegacyAlias(): bool
    {
        return $this === self::Assigned || $this === self::EnRoute;
    }

    /**
     * The modern name for this state.
     *
     * Used when comparing two statuses that mean the same thing under different
     * names, so a legacy row and a new one are never treated as different
     * points in the journey.
     */
    public function canonical(): self
    {
        return match ($this) {
            self::Assigned => self::Accepted,
            self::EnRoute => self::InTransit,
            default => $this,
        };
    }

    /** Whether a rider is still working this delivery. */
    public function isActive(): bool
    {
        return $this !== self::Delivered && $this !== self::Failed && $this !== self::Unassigned;
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
