<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Pricing;

use EruoFood\Commerce\Application\Port\ShippingCalculator;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A flat shipping fee plus an optional per-item component, waived once the
 * (post-discount) subtotal crosses the free-shipping threshold. A stand-in for
 * a carrier-rate/zone engine behind the same port.
 */
final readonly class FlatRateShippingCalculator implements ShippingCalculator
{
    public function __construct(
        private int $flatFee,
        private int $perItemFee,
        private int $freeOver,
        private string $currency,
    ) {
    }

    public function shippingFor(Money $subtotal, int $itemCount): Money
    {
        if ($this->freeOver > 0 && $subtotal->minorUnits >= $this->freeOver) {
            return new Money(0, $this->currency);
        }

        return new Money($this->flatFee + $this->perItemFee * max(0, $itemCount), $this->currency);
    }
}
