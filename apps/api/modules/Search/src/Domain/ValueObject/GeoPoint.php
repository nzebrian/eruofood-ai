<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\ValueObject;

use EruoFood\Search\Domain\Exception\SearchInvalidQuery;

/** A latitude/longitude pair used for distance sorting and geo filtering. */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            throw new SearchInvalidQuery('Coordinates are out of range.');
        }
    }

    /** Great-circle distance to another point, in kilometres (haversine). */
    public function distanceKmTo(GeoPoint $other): float
    {
        $earthKm = 6371.0088;
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLon = deg2rad($other->longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($dLon / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
