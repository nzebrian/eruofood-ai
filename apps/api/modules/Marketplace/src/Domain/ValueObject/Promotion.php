<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A discount on a menu item: either a percentage off or a fixed amount off.
 * Applying a promotion never yields a negative price (it floors at zero).
 */
final readonly class Promotion
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    public function __construct(
        public string $type,
        public int $value, // percent (0-100) or fixed minor units
    ) {
        if (! in_array($type, [self::TYPE_PERCENTAGE, self::TYPE_FIXED], true)) {
            throw new InvalidArgumentException('Unknown promotion type.');
        }
        if ($type === self::TYPE_PERCENTAGE && ($value < 1 || $value > 100)) {
            throw new InvalidArgumentException('Percentage promotion must be 1-100.');
        }
        if ($value < 0) {
            throw new InvalidArgumentException('Promotion value cannot be negative.');
        }
    }

    /** Apply the discount to a price, flooring at zero. */
    public function applyTo(Money $price): Money
    {
        $off = $this->type === self::TYPE_PERCENTAGE
            ? (int) round($price->minorUnits * $this->value / 100)
            : $this->value;

        return new Money(max(0, $price->minorUnits - $off), $price->currency);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['type'], (int) $data['value']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
