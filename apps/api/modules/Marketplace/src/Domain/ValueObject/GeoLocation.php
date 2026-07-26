<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A WGS-84 coordinate. Powers geolocation, "restaurants near me" search and the
 * distance component of delivery fees. Distance uses the haversine formula.
 */
final readonly class GeoLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    /** Great-circle distance to another point, in kilometres. */
    public function distanceKmTo(self $other): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLon = deg2rad($other->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['latitude'], (float) $data['longitude']);
    }
}
