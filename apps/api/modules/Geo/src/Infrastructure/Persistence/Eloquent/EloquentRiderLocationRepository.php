<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Rider\RiderLocation;
use EruoFood\Geo\Domain\Rider\RiderLocationRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RiderLocationModel;

/**
 * Eloquent persistence for {@see RiderLocation}.
 *
 * One row per rider, overwritten in place. That is a deliberate limit rather
 * than a simplification: keeping a trail would mean accumulating a detailed
 * record of everywhere every rider goes, and nothing in M25 reads one. When
 * live tracking needs history it should arrive with a retention policy
 * attached, not be found already collected.
 *
 * `nearby` filters staleness in SQL rather than leaving it to the caller, so
 * "riders near this restaurant" cannot quietly come to mean "riders who were
 * near it last Tuesday".
 */
final class EloquentRiderLocationRepository implements RiderLocationRepository
{
    /** @see EloquentLocationRepository::MAX_CANDIDATES for why the exact pass cannot use SQL's LIMIT. */
    private const MAX_CANDIDATES = 1_000;

    public function findByRider(string $riderId): ?RiderLocation
    {
        $model = RiderLocationModel::query()->find($riderId);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function nearby(
        Coordinates $centre,
        float $radiusMetres,
        DateTimeImmutable $freshSince,
        int $limit = 25,
    ): array {
        $box = Haversine::boundingBox($centre, $radiusMetres);

        $models = RiderLocationModel::query()
            ->where('recorded_at', '>=', $freshSince)
            ->whereBetween('latitude', [$box['minLat'], $box['maxLat']])
            ->whereBetween('longitude', [$box['minLon'], $box['maxLon']])
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $matches = [];

        foreach ($models as $model) {
            $location = $this->toDomain($model);
            $distance = Haversine::metres($centre, $location->coordinates());

            if ($distance > $radiusMetres) {
                continue;
            }

            // Fix quality is deliberately *not* filtered here. A position with
            // a two-kilometre accuracy radius is barely a position, but what
            // counts as good enough differs by caller, and a repository that
            // quietly dropped riders against a threshold nobody passed would be
            // the hardest kind of absence to notice. The accuracy travels with
            // the result and callers ask `isPreciseEnough()` themselves.
            $matches[] = ['location' => $location, 'distanceMetres' => $distance];
        }

        usort($matches, static fn (array $a, array $b): int => $a['distanceMetres'] <=> $b['distanceMetres']);

        return array_slice($matches, 0, max(0, $limit));
    }

    public function countFreshSince(DateTimeImmutable $since): int
    {
        return RiderLocationModel::query()->where('recorded_at', '>=', $since)->count();
    }

    public function save(RiderLocation $location): void
    {
        $now = new DateTimeImmutable();
        $coordinates = $location->coordinates();

        RiderLocationModel::query()->upsert(
            [[
                'rider_id' => $location->riderId(),
                'user_id' => $location->userId(),
                'latitude' => $coordinates->latitude,
                'longitude' => $coordinates->longitude,
                'accuracy_metres' => $location->accuracyMetres(),
                'heading_degrees' => $location->headingDegrees(),
                'speed_mps' => $location->speedMps(),
                'source' => $location->source()->value,
                'recorded_at' => $location->recordedAt(),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['rider_id'],
            ['user_id', 'latitude', 'longitude', 'accuracy_metres', 'heading_degrees', 'speed_mps', 'source', 'recorded_at', 'updated_at'],
        );
    }

    /**
     * Forget a rider's position.
     *
     * A real delete, unlike almost everything else on the platform. There is
     * nothing to audit in a position a rider is entitled to stop sharing when
     * they go offline, and retaining it would be keeping location data past the
     * purpose that justified collecting it.
     */
    public function forget(string $riderId): void
    {
        RiderLocationModel::query()->whereKey($riderId)->delete();
    }

    private function toDomain(RiderLocationModel $m): RiderLocation
    {
        return RiderLocation::reconstitute(
            riderId: $m->rider_id,
            userId: $m->user_id,
            coordinates: new Coordinates($m->latitude, $m->longitude),
            accuracyMetres: $m->accuracy_metres,
            headingDegrees: $m->heading_degrees,
            speedMps: $m->speed_mps,
            source: LocationSource::from($m->source),
            recordedAt: DateTimeImmutable::createFromInterface($m->recorded_at),
        );
    }
}
