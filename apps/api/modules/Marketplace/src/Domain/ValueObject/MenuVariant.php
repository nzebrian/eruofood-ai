<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A selectable variant of a menu item (e.g. "Large", "Extra protein") that
 * adjusts the base price by a delta (may be zero or negative).
 */
final readonly class MenuVariant
{
    public function __construct(
        public string $name,
        public Money $priceDelta,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            name: (string) $data['name'],
            priceDelta: new Money((int) ($data['price_delta_minor'] ?? 0), $currency),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'price_delta_minor' => $this->priceDelta->minorUnits];
    }
}
