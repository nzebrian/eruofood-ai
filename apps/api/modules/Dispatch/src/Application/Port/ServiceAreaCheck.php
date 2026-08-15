<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

/**
 * "Does the platform serve this point?", asked of M25.
 *
 * A port rather than a direct call to `DeliveryZoneService`, so Dispatch takes
 * no compile-time dependency on Geo's application layer and can be tested
 * without one. More importantly it makes the boundary explicit: Dispatch does
 * not own a definition of where the platform operates and must never grow one.
 * Two definitions of the service area is how a rider becomes eligible for a
 * delivery the checkout would have refused to quote.
 */
interface ServiceAreaCheck
{
    /**
     * Whether a point falls inside a serviceable zone.
     *
     * Returns true when no zones are configured at all: "not zoned" is not the
     * same as "nowhere is served", and refusing every rider because nobody has
     * drawn a polygon yet would take a working platform offline.
     */
    public function covers(float $latitude, float $longitude): bool;
}
