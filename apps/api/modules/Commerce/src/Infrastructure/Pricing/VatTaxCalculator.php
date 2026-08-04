<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Pricing;

use EruoFood\Commerce\Application\Port\TaxCalculator;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A single-rate VAT-style tax on the taxable amount. The rate is in basis
 * points (e.g. 750 = 7.5%). For tax-inclusive pricing the tax is the portion
 * already embedded in the amount; otherwise it is added on top.
 */
final readonly class VatTaxCalculator implements TaxCalculator
{
    public function __construct(
        private int $rateBps,
        private bool $inclusive,
    ) {
    }

    public function taxFor(Money $taxableAmount): Money
    {
        if ($this->rateBps <= 0) {
            return new Money(0, $taxableAmount->currency);
        }

        if ($this->inclusive) {
            // tax = amount - amount / (1 + rate)
            $net = (int) round($taxableAmount->minorUnits * 10000 / (10000 + $this->rateBps));

            return new Money(max(0, $taxableAmount->minorUnits - $net), $taxableAmount->currency);
        }

        return new Money((int) round($taxableAmount->minorUnits * $this->rateBps / 10000), $taxableAmount->currency);
    }
}
