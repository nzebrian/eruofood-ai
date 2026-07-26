<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Delivery;

use EruoFood\Marketplace\Application\DTO\DeliveryQuote;
use EruoFood\Marketplace\Application\Port\DeliveryFeeCalculator;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Delivery fee model: free above a subtotal threshold; otherwise a vendor-defined
 * zone fee (first zone whose radius covers the drop-off) or, failing that, a
 * distance-based fee (base + per-km, capped). Distance uses the haversine
 * helper on {@see GeoLocation}.
 */
final readonly class ZoneDeliveryFeeCalculator implements DeliveryFeeCalculator
{
    public function __construct(
        private int $baseFee,
        private int $perKmFee,
        private int $maxFee,
        private int $freeOver,
        private string $currency,
    ) {
    }

    public function quote(Vendor $vendor, ?GeoLocation $dropoff, Money $subtotal): DeliveryQuote
    {
        if ($subtotal->minorUnits >= $this->freeOver) {
            return new DeliveryQuote(new Money(0, $this->currency), 'free');
        }

        $origin = $vendor->location();
        $distanceKm = ($origin !== null && $dropoff !== null) ? $origin->distanceKmTo($dropoff) : null;

        // A matching vendor-defined zone wins.
        if ($distanceKm !== null) {
            foreach ($vendor->deliveryZones() as $zone) {
                if ($distanceKm <= $zone->radiusKm) {
                    return new DeliveryQuote($zone->fee, $zone->name);
                }
            }
        }

        // Distance-based default (or flat base fee when we have no coordinates).
        $fee = $distanceKm !== null
            ? min($this->maxFee, $this->baseFee + $this->perKmFee * (int) ceil($distanceKm))
            : $this->baseFee;

        return new DeliveryQuote(new Money($fee, $this->currency), null);
    }
}
