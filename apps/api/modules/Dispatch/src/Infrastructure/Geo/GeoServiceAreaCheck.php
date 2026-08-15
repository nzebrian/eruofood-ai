<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Geo;

use EruoFood\Dispatch\Application\Port\ServiceAreaCheck;
use EruoFood\Geo\Application\Service\DeliveryZoneService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZoneRepository;
use Throwable;

/**
 * "Does the platform serve this point?", answered by M25's delivery zones.
 *
 * Dispatch owns no second definition of where the platform operates. Two
 * definitions is how a rider becomes eligible for a delivery the checkout would
 * have refused to quote for.
 *
 * ## Two ways this deliberately says yes
 *
 * **No zones drawn at all.** M25's `isServiceable()` answers false when no zone
 * contains the point, which is right for a checkout quote and wrong here: a
 * market where nobody has drawn a polygon yet would have *every* rider rejected
 * as outside the service area, and dispatch would simply stop. "Not zoned" is
 * not "nowhere is served", so the absence of any zone is treated as no
 * restriction.
 *
 * **The zone service failed.** A bad polygon or a database hiccup should not
 * take dispatch offline across the platform to enforce a boundary check. The
 * cost of wrongly allowing a rider slightly outside a zone is a slightly longer
 * journey; the cost of wrongly refusing all of them is that nobody eats.
 *
 * Both are failing open, and both are the right way round *because this rule is
 * optional and advisory*. Nothing safety-critical rests on it — identity,
 * vehicle verification and document currency are the mandatory rules, and none
 * of them fails open.
 */
final readonly class GeoServiceAreaCheck implements ServiceAreaCheck
{
    public function __construct(
        private DeliveryZoneService $zones,
        private DeliveryZoneRepository $repository,
    ) {
    }

    public function covers(float $latitude, float $longitude): bool
    {
        try {
            if ($this->zones->isServiceable(new Coordinates($latitude, $longitude))) {
                return true;
            }

            // Only asked on the negative path, so the ordinary case stays one
            // query: is this point outside a drawn zone, or is nothing drawn?
            return $this->repository->candidatesFor(new Coordinates($latitude, $longitude)) === []
                && $this->repository->forOwner('platform', null) === [];
        } catch (Throwable) {
            return true;
        }
    }
}
