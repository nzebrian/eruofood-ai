<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\DTO;

use EruoFood\Shared\Domain\ValueObject\Money;

/** The computed money breakdown for a cart/checkout: the numbers a customer sees. */
final readonly class PriceBreakdown
{
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $shipping,
        public Money $total,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->subtotal->currency,
            'subtotal_minor' => $this->subtotal->minorUnits,
            'discount_minor' => $this->discount->minorUnits,
            'tax_minor' => $this->tax->minorUnits,
            'shipping_minor' => $this->shipping->minorUnits,
            'total_minor' => $this->total->minorUnits,
        ];
    }
}
