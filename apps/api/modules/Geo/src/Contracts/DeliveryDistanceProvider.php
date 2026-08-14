<?php

declare(strict_types=1);

namespace EruoFood\Geo\Contracts;

/**
 * How other contexts ask "how far is this delivery, really?".
 *
 * The one seam between Geo and the contexts that price deliveries. Marketplace
 * and Commerce depend on this interface and on {@see DeliveryDistance}, never on
 * Geo's domain or infrastructure — so the routing provider, the cache strategy
 * and the fallback chain can all change without touching a checkout.
 *
 * Every method returns a distance that knows its own provenance, or null. There
 * is deliberately no method that returns "a distance" without saying where it
 * came from: that signature is what would let a straight-line guess reach a
 * customer's bill unnoticed.
 */
interface DeliveryDistanceProvider
{
    /**
     * The best available measured journey between two points, or null.
     *
     * Walks the fallback chain: a fresh routed result, then a stored routed
     * result, then nothing. Never a straight-line estimate — a caller that
     * wants one can compute it itself and will not be able to mistake it for
     * this.
     */
    public function between(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
        ?string $travelMode = null,
    ): ?DeliveryDistance;

    /**
     * Whether routed distance is currently the basis for customer pricing.
     *
     * Exposed so a caller can explain a quote rather than merely produce one,
     * and so the two pricing modes are testable from the outside.
     */
    public function routedPricingEnabled(): bool;
}
