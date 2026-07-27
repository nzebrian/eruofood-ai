<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Computes the tax due on a (post-discount) taxable amount. A port so the flat
 * VAT-style default can be swapped for a jurisdiction-aware engine without
 * touching checkout.
 */
interface TaxCalculator
{
    public function taxFor(Money $taxableAmount): Money;
}
