<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Order;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A priced line in an order (a menu item + variant, at a captured unit price). */
final readonly class OrderLine
{
    public function __construct(
        public string $menuItemId,
        public string $name,
        public ?string $variantName,
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
            menuItemId: (string) $data['menu_item_id'],
            name: (string) $data['name'],
            variantName: isset($data['variant_name']) ? (string) $data['variant_name'] : null,
            unitPrice: new Money((int) $data['unit_price_minor'], $currency),
            quantity: (int) $data['quantity'],
        );
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
}
