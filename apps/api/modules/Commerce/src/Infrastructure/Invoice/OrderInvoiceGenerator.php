<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Invoice;

use EruoFood\Commerce\Application\DTO\Invoice;
use EruoFood\Commerce\Application\Port\InvoiceGenerator;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;

/**
 * Produces a structured {@see Invoice} from a placed order. Rendering to
 * PDF/HTML is a downstream concern; this generator returns the data an invoice
 * template needs. The invoice number is derived from the order reference so it
 * is stable and reproducible.
 */
final readonly class OrderInvoiceGenerator implements InvoiceGenerator
{
    public function forOrder(Order $order): Invoice
    {
        $lines = array_map(
            static fn (OrderLine $l): array => [
                'name' => $l->name,
                'variant_sku' => $l->variantSku,
                'quantity' => $l->quantity,
                'unit_price_minor' => $l->unitPrice->minorUnits,
                'line_total_minor' => $l->lineTotal()->minorUnits,
            ],
            $order->lines(),
        );

        return new Invoice(
            number: 'INV-'.$order->reference(),
            orderReference: $order->reference(),
            issuedAt: $order->placedAt()->format(DATE_ATOM),
            lines: $lines,
            subtotalMinor: $order->subtotal()->minorUnits,
            discountMinor: $order->discount()->minorUnits,
            taxMinor: $order->tax()->minorUnits,
            shippingMinor: $order->shipping()->minorUnits,
            totalMinor: $order->total()->minorUnits,
            currency: $order->total()->currency,
        );
    }
}
