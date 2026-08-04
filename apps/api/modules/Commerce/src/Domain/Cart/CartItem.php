<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Cart;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A line in a shopping cart. Its key is the (product + variant SKU) pair. */
final readonly class CartItem
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
            throw new InvalidArgumentException('Cart quantity must be at least 1.');
        }
    }

    public function key(): string
    {
        return $this->productId.'|'.($this->variantSku ?? '');
    }

    public function withQuantity(int $quantity): self
    {
        return new self($this->productId, $this->storeId, $this->name, $this->variantSku, $this->unitPrice, $quantity);
    }

    public function lineTotal(): Money
    {
        return new Money($this->unitPrice->minorUnits * $this->quantity, $this->unitPrice->currency);
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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            (string) $data['product_id'],
            (string) $data['store_id'],
            (string) $data['name'],
            isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
            new Money((int) $data['unit_price_minor'], $currency),
            (int) $data['quantity'],
        );
    }
}
