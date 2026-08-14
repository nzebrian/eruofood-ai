<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\ValueObject;

use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Search\Domain\Exception\SearchInvalidQuery;

/**
 * A latitude/longitude pair used for distance sorting and geo filtering.
 *
 * Kept as the Search context's own type; the distance arithmetic delegates to
 * the platform's canonical {@see Haversine} so there is one implementation
 * rather than the two that existed before M25.
 */
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
        return Haversine::kilometres($this->toCoordinates(), $other->toCoordinates());
    }

    public function toCoordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }
}
