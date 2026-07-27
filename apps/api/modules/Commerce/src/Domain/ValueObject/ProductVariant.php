<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A purchasable variant of a product (e.g. "1kg", "Red / L"), with its own SKU
 * and a price delta relative to the product's base price. Stock for a variant
 * is tracked per SKU by the Inventory aggregate.
 */
final readonly class ProductVariant
{
    public function __construct(
        public string $sku,
        public string $name,
        public Money $priceDelta,
        public ?string $barcode = null,
    ) {
        if (trim($sku) === '') {
            throw new InvalidArgumentException('Variant SKU cannot be empty.');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Variant name cannot be empty.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            (string) $data['sku'],
            (string) $data['name'],
            new Money((int) ($data['price_delta_minor'] ?? 0), $currency),
            isset($data['barcode']) ? (string) $data['barcode'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'price_delta_minor' => $this->priceDelta->minorUnits,
            'barcode' => $this->barcode,
        ];
    }
}
