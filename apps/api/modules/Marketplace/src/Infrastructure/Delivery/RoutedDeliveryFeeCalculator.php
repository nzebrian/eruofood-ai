<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Delivery;

use EruoFood\Geo\Contracts\DeliveryDistance;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
use EruoFood\Geo\Domain\Exception\GeoRoutingUnavailable;
use EruoFood\Marketplace\Application\DTO\DeliveryQuote;
use EruoFood\Marketplace\Application\Port\DeliveryFeeCalculator;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Shared\Domain\ValueObject\Money;
use Psr\Log\LoggerInterface;

/**
 * Delivery pricing on measured road distance, behind a switch.
 *
 * Wraps the pre-M25 {@see ZoneDeliveryFeeCalculator} rather than replacing it,
 * which is what makes the change reversible: with the switch off this class
 * delegates every quote unchanged, so turning routed pricing on and off is a
 * configuration edit with no deploy and no data migration.
 *
 * ## Why this exists
 *
 * The wrapped calculator charges per kilometre of *straight-line* distance. In
 * Lagos the road distance commonly runs 1.3–1.6× that, so the old fee did not
 * merely wobble around the true cost — it understated it, in one direction, on
 * every order. Correcting that raises real prices, which is why it is a
 * deliberate act rather than a deployment side effect.
 *
 * ## The chain, when routed pricing is on
 *
 *   1. **A billable routed distance** — fresh, or cached within its grace
 *      period. Priced per kilometre of actual road.
 *   2. **The merchant's flat zone fee** — a price the merchant published and
 *      stands behind, and one that does not depend on measuring the journey.
 *      Only reachable when an operator has turned `refuse_when_unavailable`
 *      off.
 *   3. **An honest refusal.** The default. The customer is told delivery
 *      pricing is unavailable and offered pickup.
 *
 * There is no haversine step. Not as a last resort, not "just this once for
 * checkout" — a straight-line fee is available at every one of those points and
 * is wrong at all of them, which is precisely why it must not be reachable.
 */
final readonly class RoutedDeliveryFeeCalculator implements DeliveryFeeCalculator
{
    public function __construct(
        private DeliveryFeeCalculator $legacy,
        private DeliveryDistanceProvider $distances,
        private LoggerInterface $logger,
        private int $baseFee,
        private int $perKmFee,
        private int $maxFee,
        private int $freeOver,
        private string $currency,
        private bool $routedPricingEnabled,
        private bool $shadowMode,
        private bool $refuseWhenUnavailable,
    ) {
    }

    public function quote(Vendor $vendor, ?GeoLocation $dropoff, Money $subtotal): DeliveryQuote
    {
        if (! $this->routedPricingEnabled) {
            // The pre-M25 path, byte for byte. Shadow mode may measure
            // alongside it, but the number returned here is the old number.
            $quote = $this->legacy->quote($vendor, $dropoff, $subtotal);

            if ($this->shadowMode) {
                $this->observeWithoutCharging($vendor, $dropoff, $subtotal, $quote);
            }

            return $quote;
        }

        // Free delivery is a subtotal rule and owes nothing to distance, so it
        // is settled before any provider is troubled — a free delivery should
        // not cost a routing call, and must not be refused because routing is
        // down.
        if ($subtotal->minorUnits >= $this->freeOver) {
            return new DeliveryQuote(new Money(0, $this->currency), 'free');
        }

        $pickup = $vendor->location();

        if ($pickup === null || $dropoff === null) {
            // No coordinates at either end: nothing to route. This is a
            // merchant-configuration problem, not an outage, and it falls to
            // the same honest end of the chain.
            return $this->withoutRouting($vendor, 'missing_coordinates');
        }

        $distance = $this->distances->between(
            $pickup->latitude,
            $pickup->longitude,
            $dropoff->latitude,
            $dropoff->longitude,
        );

        if ($distance === null || ! $distance->isBillable) {
            return $this->withoutRouting($vendor, $distance === null ? 'no_route' : 'route_too_old');
        }

        return $this->priceOn($vendor, $distance);
    }

    /**
     * Price a journey we actually measured.
     *
     * A merchant's own zone fee still wins when the delivery falls inside it —
     * that is a price the merchant set deliberately, and routed distance is
     * here to make the *distance* honest, not to overrule merchant pricing.
     * What changes is which zone matches and what the per-kilometre default
     * costs, both of which now reflect the road rather than the crow.
     */
    private function priceOn(Vendor $vendor, DeliveryDistance $distance): DeliveryQuote
    {
        $km = $distance->distanceKm();

        foreach ($vendor->deliveryZones() as $zone) {
            if ($km <= $zone->radiusKm) {
                return new DeliveryQuote($zone->fee, $zone->name);
            }
        }

        $fee = min($this->maxFee, $this->baseFee + $this->perKmFee * (int) ceil($km));

        return new DeliveryQuote(new Money($fee, $this->currency), null);
    }

    /**
     * No billable distance — the last two rungs of the chain.
     *
     * Refusal is the default and the honest answer: declining to price a
     * delivery is a poor experience, and charging confidently for a journey
     * nobody measured is worse, because at scale it is a systematic
     * one-directional error that nobody notices.
     */
    private function withoutRouting(Vendor $vendor, string $reason): DeliveryQuote
    {
        $flat = $this->merchantFlatFee($vendor);

        if (! $this->refuseWhenUnavailable && $flat !== null) {
            $this->logger->info('Delivery priced on a merchant flat fee because routing was unavailable.', [
                'vendor_id' => $vendor->id(),
                'reason' => $reason,
            ]);

            return $flat;
        }

        $this->logger->warning('Refused to quote a delivery fee: no measured distance available.', [
            'vendor_id' => $vendor->id(),
            'reason' => $reason,
        ]);

        throw new GeoRoutingUnavailable();
    }

    /**
     * The merchant's published flat fee, when they have one.
     *
     * The **lowest** of their configured zone fees. Every one of these is a
     * price the merchant advertised, and picking the cheapest means a customer
     * is never charged more than a published price for a journey the platform
     * could not measure. The platform absorbs the difference; that is the right
     * side to err on when the failure is ours.
     */
    private function merchantFlatFee(Vendor $vendor): ?DeliveryQuote
    {
        $best = null;

        foreach ($vendor->deliveryZones() as $zone) {
            if ($best === null || $zone->fee->minorUnits < $best->fee->minorUnits) {
                $best = new DeliveryQuote($zone->fee, $zone->name);
            }
        }

        return $best;
    }

    /**
     * Measure the difference without charging for it.
     *
     * The point of shadow mode: the size of the pricing change is knowable from
     * real orders before a single customer feels it. Logged with the vendor and
     * the two fees — never with the customer's coordinates, which are not
     * needed to understand the spread.
     */
    private function observeWithoutCharging(Vendor $vendor, ?GeoLocation $dropoff, Money $subtotal, DeliveryQuote $charged): void
    {
        $pickup = $vendor->location();

        if ($pickup === null || $dropoff === null || $subtotal->minorUnits >= $this->freeOver) {
            return;
        }

        $distance = $this->distances->between(
            $pickup->latitude,
            $pickup->longitude,
            $dropoff->latitude,
            $dropoff->longitude,
        );

        if ($distance === null || ! $distance->isBillable) {
            return;
        }

        $wouldCharge = $this->priceOn($vendor, $distance);

        $this->logger->info('Routed delivery pricing shadow comparison.', [
            'vendor_id' => $vendor->id(),
            'charged_minor' => $charged->fee->minorUnits,
            'would_charge_minor' => $wouldCharge->fee->minorUnits,
            'difference_minor' => $wouldCharge->fee->minorUnits - $charged->fee->minorUnits,
            'routed_distance_metres' => $distance->distanceMetres,
            'route_source' => $distance->source,
        ]);
    }
}
