<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/**
 * The assignment's own lifecycle — and the boundary with Marketplace.
 *
 * ## Who owns what (M26 decision 1, option b)
 *
 * Marketplace's `Delivery` remains the operational delivery aggregate and owns
 * the *journey*: en route to pickup, arrived, picked up, in transit, delivered.
 * Dispatch owns the *relationship* between a rider and that delivery: who was
 * offered it, who accepted, whether it needs reassigning.
 *
 * These two state machines must not contradict each other, so the rule is
 * one-directional and narrow:
 *
 * - Dispatch is authoritative from `Accepted` until the rider starts moving.
 *   Nothing in Marketplace may create or change an assignment.
 * - Once the rider begins the journey, **Marketplace leads**. Its `Delivery`
 *   advances through its own states, and each advance is mirrored here through
 *   {@see forDeliveryStatus()} so Dispatch's record stays truthful without
 *   owning the decision.
 * - Only `Cancelled` and `ReassignmentRequired` may be entered from the
 *   Dispatch side once the journey has begun, and both end the assignment.
 *
 * The mirror is a projection of a decision Marketplace already made — never a
 * second place the journey can be advanced from. That is what stops the two
 * machines disagreeing.
 */
enum AssignmentState: string
{
    case Accepted = 'accepted';
    case EnRoutePickup = 'en_route_pickup';
    case ArrivedPickup = 'arrived_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';

    case Cancelled = 'cancelled';
    case ReassignmentRequired = 'reassignment_required';

    /**
     * The states an assignment may move to from here.
     *
     * Written as an explicit table rather than an ordinal comparison, because
     * the pre-M26 `Delivery` used a `+1` ordinal table and it encoded the wrong
     * order — its `en_route` sat *after* `picked_up`, the opposite of
     * `EN_ROUTE_PICKUP`. An explicit table cannot be wrong quietly.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Accepted => [self::EnRoutePickup, self::Cancelled, self::ReassignmentRequired],
            self::EnRoutePickup => [self::ArrivedPickup, self::Cancelled, self::ReassignmentRequired],
            self::ArrivedPickup => [self::PickedUp, self::Cancelled, self::ReassignmentRequired],
            // Past this point the rider holds the customer's food. Reassignment
            // is no longer a dispatch decision — it is an operational incident,
            // so only an explicit cancellation can end it here.
            self::PickedUp => [self::InTransit, self::Cancelled],
            self::InTransit => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled, self::ReassignmentRequired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Whether this assignment still occupies the rider and the delivery. */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * States that hold the exclusive slot on a rider and on a delivery.
     *
     * This list is the application-side mirror of the partial unique indexes on
     * `dispatch_assignments`. They must agree: the database is the guarantee,
     * this is the readable statement of it.
     *
     * @return list<string>
     */
    public static function occupyingValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isActive()),
        ));
    }

    /**
     * The assignment state mirroring a Marketplace delivery status.
     *
     * The one-way bridge described above. Null means the delivery status has no
     * assignment meaning and Dispatch should leave its record alone.
     */
    public static function forDeliveryStatus(string $deliveryStatus): ?self
    {
        return match ($deliveryStatus) {
            'en_route_pickup' => self::EnRoutePickup,
            'arrived_pickup' => self::ArrivedPickup,
            'picked_up' => self::PickedUp,
            'in_transit' => self::InTransit,
            'delivered' => self::Delivered,
            default => null,
        };
    }
}
