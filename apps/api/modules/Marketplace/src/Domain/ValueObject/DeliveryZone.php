<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A named area a vendor delivers to, with a flat fee and a coverage radius (km)
 * from the vendor. A vendor-defined zone fee overrides the distance-based
 * default; the radius bounds where the zone applies (polygon zones are a future
 * enhancement — architecture-ready).
 */
final readonly class DeliveryZone
{
    public function __construct(
        public string $name,
        public Money $fee,
        public float $radiusKm,
    ) {
        if ($radiusKm <= 0) {
            throw new InvalidArgumentException('Delivery zone radius must be positive.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            name: (string) $data['name'],
            fee: new Money((int) ($data['fee_minor'] ?? 0), $currency),
            radiusKm: (float) ($data['radius_km'] ?? 5),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'fee_minor' => $this->fee->minorUnits,
            'radius_km' => $this->radiusKm,
        ];
    }
}
