<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\DTO;

/**
 * A structured invoice document generated from an order. Rendering (PDF/HTML)
 * is a downstream concern; this is the data.
 *
 * @phpstan-type InvoiceLine array{name: string, variant_sku: string|null, quantity: int, unit_price_minor: int, line_total_minor: int}
 */
final readonly class Invoice
{
    /**
     * @param list<array{name: string, variant_sku: string|null, quantity: int, unit_price_minor: int, line_total_minor: int}> $lines
     */
    public function __construct(
        public string $number,
        public string $orderReference,
        public string $issuedAt,
        public array $lines,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $taxMinor,
        public int $shippingMinor,
        public int $totalMinor,
        public string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'order_reference' => $this->orderReference,
            'issued_at' => $this->issuedAt,
            'lines' => $this->lines,
            'subtotal_minor' => $this->subtotalMinor,
            'discount_minor' => $this->discountMinor,
            'tax_minor' => $this->taxMinor,
            'shipping_minor' => $this->shippingMinor,
            'total_minor' => $this->totalMinor,
            'currency' => $this->currency,
        ];
    }
}
