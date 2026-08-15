<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

/**
 * The delivery, as Dispatch is allowed to touch it.
 *
 * M26 decision 1: Marketplace's `Delivery` stays the operational delivery
 * aggregate. Dispatch does not copy it, does not own its states, and reaches it
 * only through these four calls.
 *
 * Note how narrow that is. There is no method here that reads a fee, a customer
 * address or a track point, because Dispatch has no business with any of them —
 * and a port that cannot express a thing is a boundary that cannot erode into
 * expressing it.
 */
interface DeliveryLifecycle
{
    /** The delivery's current status string, or null if there is no such delivery. */
    public function statusOf(string $deliveryId): ?string;

    /** Record that a rider is being asked. Returns false if the delivery would not allow it. */
    public function markOffered(string $deliveryId): bool;

    /**
     * Record that a rider accepted.
     *
     * Called inside Dispatch's assignment transaction, so a delivery is never
     * left claiming a rider whose assignment was rolled back.
     */
    public function riderAccepted(string $deliveryId, string $riderId): bool;

    /**
     * Advance the journey.
     *
     * **Marketplace decides.** This is the authoritative call — Dispatch mirrors
     * the outcome into its own assignment afterwards, rather than advancing its
     * assignment and telling Marketplace about it. Returns false when the
     * delivery refuses the transition, so the caller can leave its own record
     * untouched rather than drifting ahead of the delivery.
     */
    public function advance(string $deliveryId, string $status): bool;

    /** Release the rider and put the delivery back to unassigned. */
    public function releaseRider(string $deliveryId): bool;
}
