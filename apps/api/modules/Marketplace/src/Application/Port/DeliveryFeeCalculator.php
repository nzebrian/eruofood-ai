<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Port;

use EruoFood\Marketplace\Application\DTO\DeliveryQuote;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Computes the delivery fee for an order from the vendor, the drop-off location
 * and the order subtotal. A vendor-defined delivery zone (matched by radius)
 * overrides the distance-based default; large orders may ship free.
 */
interface DeliveryFeeCalculator
{
    public function quote(Vendor $vendor, ?GeoLocation $dropoff, Money $subtotal): DeliveryQuote;
}
