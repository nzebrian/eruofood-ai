<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Computes the shipping fee for an order. A port so the flat/threshold default
 * can be swapped for a carrier-rate or zone-based engine. Pickup orders never
 * reach this — the checkout waives shipping for pickup itself.
 */
interface ShippingCalculator
{
    public function shippingFor(Money $subtotal, int $itemCount): Money;
}
