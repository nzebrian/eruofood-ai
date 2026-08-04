<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Resolves the effective selling price of a product/variant. The default
 * implementation returns the catalogue price (after any active promotion);
 * this port is the seam for dynamic pricing (demand/inventory/time based) —
 * architecture-ready without committing to an algorithm.
 */
interface PricingStrategy
{
    public function priceFor(Product $product, ?string $variantSku): Money;
}
