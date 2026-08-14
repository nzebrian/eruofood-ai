<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\Route\RouteCacheRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RouteCacheModel;
use Illuminate\Support\Str;

/**
 * Durable route storage, sitting behind the Redis cache.
 *
 * This table earns its place in one specific moment: the provider is down,
 * Redis has been flushed or restarted, and a customer is checking out. A route
 * from this morning is a defensible basis for a delivery fee. A straight-line
 * guess is not, at any age — which is why everything read back from here is
 * badged {@see RouteSource::Cache} and never as a fresh provider result. The
 * badge is what lets pricing apply the grace period instead of trusting a row
 * simply because it exists.
 */
final class EloquentRouteCacheRepository implements RouteCacheRepository
{
    /**
     * @param int $staleGraceSeconds The age past which the hot read path stops
     *                               offering a row at all. Pricing checks the
     *                               age again on the domain object; this is
     *                               the cheaper first cut, not the decision.
     */
    public function __construct(private readonly int $staleGraceSeconds)
    {
    }

    public function findByKey(string $cacheKey): ?Route
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d seconds', max(0, $this->staleGraceSeconds)));

        $model = RouteCacheModel::query()
            ->where('cache_key', $cacheKey)
            ->where('calculated_at', '>=', $cutoff)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByKeyRegardlessOfAge(string $cacheKey): ?Route
    {
        $model = RouteCacheModel::query()->where('cache_key', $cacheKey)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    /**
     * Write, or overwrite the existing entry for this key.
     *
     * Keyed on `cache_key` rather than on a surrogate id, so two concurrent
     * quotes for the same journey converge on one row instead of racing to
     * insert two — and so the newer measurement wins, which is the one worth
     * keeping.
     */
    public function store(string $cacheKey, Route $route): void
    {
        $now = new DateTimeImmutable();

        RouteCacheModel::query()->upsert(
            [[
                'id' => (string) Str::orderedUuid(),
                'cache_key' => $cacheKey,
                'origin_latitude' => $route->origin->latitude,
                'origin_longitude' => $route->origin->longitude,
                'destination_latitude' => $route->destination->latitude,
                'destination_longitude' => $route->destination->longitude,
                'travel_mode' => $route->travelMode->value,
                'traffic_aware' => $route->durationInTrafficSeconds !== null,
                'distance_metres' => $route->distanceMetres,
                'duration_seconds' => $route->durationSeconds,
                'duration_in_traffic_seconds' => $route->durationInTrafficSeconds,
                'provider' => $route->provider,
                'provider_route_id' => $route->providerRouteId,
                'calculated_at' => $route->calculatedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['cache_key'],
            [
                'origin_latitude', 'origin_longitude', 'destination_latitude', 'destination_longitude',
                'travel_mode', 'traffic_aware', 'distance_metres', 'duration_seconds',
                'duration_in_traffic_seconds', 'provider', 'provider_route_id', 'calculated_at', 'updated_at',
            ],
        );
    }

    public function purgeOlderThan(DateTimeImmutable $before): int
    {
        return RouteCacheModel::query()->where('calculated_at', '<', $before)->delete();
    }

    private function toDomain(RouteCacheModel $m): Route
    {
        return new Route(
            origin: new Coordinates($m->origin_latitude, $m->origin_longitude),
            destination: new Coordinates($m->destination_latitude, $m->destination_longitude),
            distanceMetres: $m->distance_metres,
            durationSeconds: $m->duration_seconds,
            travelMode: TravelMode::from($m->travel_mode),
            // Always `Cache`, never the source it originally had. A stored route
            // is evidence of a past measurement, and pricing must be able to
            // tell that apart from a call made a second ago.
            source: RouteSource::Cache,
            provider: $m->provider,
            calculatedAt: DateTimeImmutable::createFromInterface($m->calculated_at),
            durationInTrafficSeconds: $m->duration_in_traffic_seconds,
            providerRouteId: $m->provider_route_id,
        );
    }
}
