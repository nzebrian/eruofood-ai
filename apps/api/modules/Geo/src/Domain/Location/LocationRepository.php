<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Location;

use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * Persistence port for {@see Location}.
 *
 * `withinRadius` is the seam that keeps PostGIS a future option rather than a
 * present dependency: today it is a bounding-box prefilter plus an exact
 * haversine pass, tomorrow it could be `ST_DWithin`, and no caller changes.
 */
interface LocationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Location;

    /**
     * @param list<string> $ids
     * @return list<Location>
     */
    public function findByIds(array $ids): array;

    /**
     * Locations within $radiusMetres of $centre, nearest first.
     *
     * @return list<array{location: Location, distanceMetres: float}>
     */
    public function withinRadius(Coordinates $centre, float $radiusMetres, int $limit = 50): array;

    /**
     * Locations still awaiting a geocode, for the backfill sweep.
     *
     * @return list<Location>
     */
    public function needingGeocode(int $limit = 100): array;

    public function save(Location $location): void;
}
