<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

use EruoFood\Commerce\Application\DTO\DiscountResult;
use EruoFood\Commerce\Domain\Cart\Cart;

/**
 * Resolves the total order-level discount (coupon + any automatic promotions)
 * for a cart at checkout, and reports whether shipping is waived. A port so the
 * discount rules can evolve independently of checkout.
 */
interface DiscountEngine
{
    public function evaluate(Cart $cart): DiscountResult;
}
