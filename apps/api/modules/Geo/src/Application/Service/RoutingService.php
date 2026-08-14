<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Application\DTO\RouteMatrixResult;
use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Application\Port\GeoCache;
use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Event\RouteCalculated;
use EruoFood\Geo\Domain\Event\RouteCalculationFailed;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\Route\RouteCacheRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Shared\Domain\EventBus;
use Throwable;

/**
 * Road distance and duration, with the fallback chain that decides what may be
 * billed.
 *
 * ## The chain, in order
 *
 * 1. **Redis** — a route measured minutes ago, free and instant.
 * 2. **The route table** — durable, survives a Redis flush, still inside grace.
 * 3. **The provider** — a fresh measurement.
 * 4. **A stale stored route** — older than grace but real, offered explicitly
 *    to the caller rather than silently substituted.
 * 5. **Nothing.** The caller falls through to a merchant flat-zone fee or
 *    refuses to quote.
 *
 * There is deliberately no haversine step. A straight-line distance is a
 * perfectly good answer to "roughly how far?" and a systematically wrong answer
 * to "what should this cost?" — in Lagos it understates road distance by 30–60%,
 * in one direction, on every order. It is not in this chain at any position,
 * and {@see Route::isBillable()} would refuse it if it were.
 *
 * `attempt()` is what makes step 5 usable: it returns null instead of throwing,
 * so the caller can consult a flat fee and then decline, rather than having to
 * catch an exception to discover a business outcome.
 */
final readonly class RoutingService
{
    public function __construct(
        private GeoProviderRegistry $providers,
        private GeoCache $cache,
        private RouteCacheRepository $routes,
        private ProviderGuard $guard,
        private EventBus $events,
        private int $routeTtl,
        private int $trafficRouteTtl,
        private int $matrixTtl,
        private int $cachePrecision,
    ) {
    }

    /**
     * A route, or an exception if none can be established.
     *
     * For anything that prices a delivery, prefer {@see attempt()}: refusing to
     * quote is a business outcome, not an error condition.
     */
    public function route(RouteQuery $query): Route
    {
        $route = $this->attempt($query);

        if ($route === null) {
            throw GeoProviderUnavailable::because('No route could be established.');
        }

        return $route;
    }

    /**
     * Walk the chain and return whatever it yields, including nothing.
     *
     * A returned route always carries its own `source` and `calculatedAt`, so
     * the caller can — and must — decide whether it is fresh enough to charge
     * for. This method's job is to find the best available answer, not to
     * decide what it is worth.
     */
    public function attempt(RouteQuery $query): ?Route
    {
        $key = $this->cacheKey($query);

        $hot = $this->fromHotCache($key);

        if ($hot !== null) {
            $this->guard->recordCacheHit($this->providerName($query), 'route');

            return $hot;
        }

        $stored = $this->routes->findByKey($key);

        if ($stored !== null) {
            $this->guard->recordCacheHit($this->providerName($query), 'route');
            $this->cache->put($key, $this->toCache($stored), $this->ttlFor($query));

            return $stored;
        }

        // Only the provider call is guarded. Widening this `try` to cover the
        // writes below would let a persistence bug impersonate an outage — and
        // that is not hypothetical: an oversized cache-key column made every
        // store throw, the catch turned it into "provider unavailable", and the
        // result was a permanent hundred-percent cache miss that looked from
        // the outside like Google having a bad week.
        try {
            $provider = $this->providers->routing($query->countryCode);

            $route = $this->guard->call(
                $provider->name(),
                'route',
                fn (): Route => $provider->route($query),
            );
        } catch (Throwable $e) {
            // An outage, a spent quota, an open circuit, an unroutable pair.
            // They differ in the telemetry code and not in what happens next,
            // which is to look for the last real answer and, failing that, to
            // admit there isn't one.
            return $this->staleFallback($key, $query, $e);
        }

        $this->cache->put($key, $this->toCache($route), $this->ttlFor($query));
        $this->routes->store($key, $route);

        $this->events->publish(new RouteCalculated(
            provider: $route->provider,
            source: $route->source->value,
            distanceMetres: $route->distanceMetres,
            durationSeconds: $route->durationSeconds,
            travelMode: $route->travelMode->value,
        ));

        return $route;
    }

    /**
     * Many-to-many distances, for shortlisting.
     *
     * Not cached per pair: a matrix is asked about a different set of points
     * almost every time, so a per-pair cache would mostly miss while making
     * every call slower. The whole result gets a short lifetime instead.
     *
     * @param list<Coordinates> $origins
     * @param list<Coordinates> $destinations
     */
    public function matrix(array $origins, array $destinations, TravelMode $travelMode, ?string $countryCode = null): RouteMatrixResult
    {
        if ($origins === [] || $destinations === []) {
            return new RouteMatrixResult([], 'none');
        }

        $provider = $this->providers->distanceMatrix($countryCode);

        $key = 'matrix:'.hash('sha256', implode(';', array_merge(
            array_map(fn (Coordinates $c): string => $c->roundedTo($this->cachePrecision)->toKey($this->cachePrecision), $origins),
            ['|'],
            array_map(fn (Coordinates $c): string => $c->roundedTo($this->cachePrecision)->toKey($this->cachePrecision), $destinations),
            [$travelMode->value],
        )));

        $cached = $this->cache->get($key);

        if ($cached !== null && isset($cached['cells']) && is_array($cached['cells'])) {
            $this->guard->recordCacheHit($provider->name(), 'matrix');

            return new RouteMatrixResult($cached['cells'], (string) ($cached['provider'] ?? $provider->name()));
        }

        $result = $this->guard->call(
            $provider->name(),
            'matrix',
            fn (): RouteMatrixResult => $provider->matrix($origins, $destinations, $travelMode),
        );

        $this->cache->put($key, ['cells' => $result->cells, 'provider' => $result->provider], $this->matrixTtl);

        return $result;
    }

    /**
     * The last real answer available, however old.
     *
     * Returned rather than swallowed because a route from this morning is
     * evidence, and the caller is better placed to judge it than this method
     * is — it comes back badged `Cache` with its original timestamp, so
     * {@see Route::isBillable()} can still refuse it.
     */
    private function staleFallback(string $key, RouteQuery $query, Throwable $cause): ?Route
    {
        $this->events->publish(new RouteCalculationFailed(
            provider: $this->providerName($query),
            reason: $cause instanceof GeoQuotaExceeded ? 'GEO_QUOTA_EXCEEDED' : 'GEO_PROVIDER_UNAVAILABLE',
            travelMode: $query->travelMode->value,
        ));

        // Whatever the table holds, however old, badged `Cache` with its
        // original timestamp. Judging its age here would hide the decision from
        // the one caller that has to make it: `Route::isBillable()` refuses a
        // route past grace, and a caller that only wants to *show* an estimate
        // can legitimately use one this method would have thrown away.
        return $this->routes->findByKeyRegardlessOfAge($key);
    }

    /**
     * Origin, destination, mode and traffic flag.
     *
     * Rounded to about eleven metres, which is what makes a hit possible at
     * all: two orders from opposite ends of the same building should share an
     * answer. The mode is in the key because a motorbike and a car do not take
     * the same path through Lagos, and the traffic flag is in it because a
     * traffic-aware duration and a free-flow one are different questions.
     */
    private function cacheKey(RouteQuery $query): string
    {
        return 'route:'.hash('sha256', implode('|', [
            $query->origin->roundedTo($this->cachePrecision)->toKey($this->cachePrecision),
            $query->destination->roundedTo($this->cachePrecision)->toKey($this->cachePrecision),
            $query->travelMode->value,
            $query->trafficAware ? 'traffic' : 'free',
        ]));
    }

    /**
     * A traffic-aware answer cached for hours stops being traffic-aware and
     * becomes a confidently wrong number, so it gets minutes.
     */
    private function ttlFor(RouteQuery $query): int
    {
        return $query->trafficAware ? $this->trafficRouteTtl : $this->routeTtl;
    }

    private function providerName(RouteQuery $query): string
    {
        try {
            return $this->providers->routing($query->countryCode)->name();
        } catch (Throwable) {
            // Telemetry must never be the reason a cached route fails to serve.
            return 'unknown';
        }
    }

    private function fromHotCache(string $key): ?Route
    {
        $cached = $this->cache->get($key);

        if ($cached === null) {
            return null;
        }

        $origin = Coordinates::tryFromMixed($cached['originLat'] ?? null, $cached['originLon'] ?? null);
        $destination = Coordinates::tryFromMixed($cached['destLat'] ?? null, $cached['destLon'] ?? null);
        $mode = TravelMode::tryFrom((string) ($cached['travelMode'] ?? ''));

        if ($origin === null || $destination === null || $mode === null || ! isset($cached['distanceMetres'], $cached['calculatedAt'])) {
            return null;
        }

        return new Route(
            origin: $origin,
            destination: $destination,
            distanceMetres: (int) $cached['distanceMetres'],
            durationSeconds: (int) ($cached['durationSeconds'] ?? 0),
            travelMode: $mode,
            // Everything read back is cache, whatever it was when written.
            source: RouteSource::Cache,
            provider: (string) ($cached['provider'] ?? 'unknown'),
            calculatedAt: new DateTimeImmutable('@'.(int) $cached['calculatedAt']),
            durationInTrafficSeconds: isset($cached['durationInTrafficSeconds']) ? (int) $cached['durationInTrafficSeconds'] : null,
            providerRouteId: is_string($cached['providerRouteId'] ?? null) ? $cached['providerRouteId'] : null,
            polyline: is_string($cached['polyline'] ?? null) ? $cached['polyline'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function toCache(Route $route): array
    {
        return [
            'originLat' => $route->origin->latitude,
            'originLon' => $route->origin->longitude,
            'destLat' => $route->destination->latitude,
            'destLon' => $route->destination->longitude,
            'travelMode' => $route->travelMode->value,
            'distanceMetres' => $route->distanceMetres,
            'durationSeconds' => $route->durationSeconds,
            'durationInTrafficSeconds' => $route->durationInTrafficSeconds,
            'provider' => $route->provider,
            'providerRouteId' => $route->providerRouteId,
            'polyline' => $route->polyline,
            // A timestamp, not a formatted date: the age is what decides
            // billability and it must survive serialisation exactly.
            'calculatedAt' => $route->calculatedAt->getTimestamp(),
        ];
    }
}
