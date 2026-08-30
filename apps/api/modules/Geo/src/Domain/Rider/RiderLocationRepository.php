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

    /**
     * How many stored positions were last recorded before $before (M42).
     *
     * Reports only; deletes nothing, so a dry run can be read before anything
     * irreversible happens.
     */
    public function countRecordedBefore(DateTimeImmutable $before): int;

    /**
     * Delete stored positions last recorded before $before, in bounded batches.
     *
     * This table holds ONE row per rider — `rider_id` is the primary key and a
     * new fix upserts over the old one — so this is not trimming a movement
     * trail. It removes the last known position of a rider who has not reported
     * since the cutoff: a coordinate that is no longer operationally useful and
     * has become a record of where somebody was.
     *
     * @return int rows removed
     */
    public function purgeRecordedBefore(DateTimeImmutable $before, int $chunkSize): int;
}
