<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Rider;

use DateTimeImmutable;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/** Persistence port for {@see RiderLocation}. */
interface RiderLocationRepository
{
    public function findByRider(string $riderId): ?RiderLocation;

    /**
     * Riders whose last known position falls within a radius and is not stale.
     *
     * The shape M26 dispatch will need. Staleness is filtered here rather than
     * by the caller so that "nearby riders" cannot accidentally mean "riders
     * who were nearby last week".
     *
     * @return list<array{location: RiderLocation, distanceMetres: float}>
     */
    public function nearby(
        Coordinates $centre,
        float $radiusMetres,
        DateTimeImmutable $freshSince,
        int $limit = 25,
    ): array;

    /** How many riders reported recently — a health signal, not a location read. */
    public function countFreshSince(DateTimeImmutable $since): int;

    public function save(RiderLocation $location): void;

    /** Forget a rider's position, e.g. when they go offline. */
    public function forget(string $riderId): void;
}
