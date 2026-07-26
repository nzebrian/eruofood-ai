<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Cart;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A line in a shopping cart. Its key is the (menu item + variant) pair. */
final readonly class CartItem
{
    public function __construct(
        public string $menuItemId,
        public string $name,
        public ?string $variantName,
        public Money $unitPrice,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Cart quantity must be at least 1.');
        }
    }

    public function key(): string
    {
        return $this->menuItemId.'|'.($this->variantName ?? '');
    }

    public function withQuantity(int $quantity): self
    {
        return new self($this->menuItemId, $this->name, $this->variantName, $this->unitPrice, $quantity);
    }

    public function lineTotal(): Money
    {
        return new Money($this->unitPrice->minorUnits * $this->quantity, $this->unitPrice->currency);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'menu_item_id' => $this->menuItemId,
            'name' => $this->name,
            'variant_name' => $this->variantName,
            'unit_price_minor' => $this->unitPrice->minorUnits,
            'quantity' => $this->quantity,
            'line_total_minor' => $this->lineTotal()->minorUnits,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            (string) $data['menu_item_id'],
            (string) $data['name'],
            isset($data['variant_name']) ? (string) $data['variant_name'] : null,
            new Money((int) $data['unit_price_minor'], $currency),
            (int) $data['quantity'],
        );
    }
}
