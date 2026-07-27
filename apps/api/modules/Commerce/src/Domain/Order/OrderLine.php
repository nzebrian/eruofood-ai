<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Order;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A priced line in an order (a product + variant, at a captured unit price). */
final readonly class OrderLine
{
    public function __construct(
        public string $productId,
        public string $storeId,
        public string $name,
        public ?string $variantSku,
        public Money $unitPrice,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Order line quantity must be at least 1.');
        }
    }

    public function lineTotal(): Money
    {
        return new Money($this->unitPrice->minorUnits * $this->quantity, $this->unitPrice->currency);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            productId: (string) $data['product_id'],
            storeId: (string) $data['store_id'],
            name: (string) $data['name'],
            variantSku: isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
            unitPrice: new Money((int) $data['unit_price_minor'], $currency),
            quantity: (int) $data['quantity'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'store_id' => $this->storeId,
            'name' => $this->name,
            'variant_sku' => $this->variantSku,
            'unit_price_minor' => $this->unitPrice->minorUnits,
            'quantity' => $this->quantity,
            'line_total_minor' => $this->lineTotal()->minorUnits,
        ];
    }
}
