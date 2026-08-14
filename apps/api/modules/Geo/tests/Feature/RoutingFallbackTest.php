<?php

declare(strict_types=1);

use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Application\Port\DistanceMatrixProvider;
use EruoFood\Geo\Application\Port\GeocodingProvider;
use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Application\Port\PlacesProvider;
use EruoFood\Geo\Application\Port\RoutingProvider;
use EruoFood\Geo\Application\Service\RoutingService;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Event\RouteCalculationFailed;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\Route\RouteCacheRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\ProviderRequestModel;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RouteCacheModel;
use EruoFood\Shared\Domain\DomainEvent;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * The approved fallback chain, driven end to end.
 *
 *   fresh routed → stale cached routed → (merchant flat fee) → honest refusal
 *
 * The chain has a deliberate hole in it: there is no haversine step. A
 * straight-line distance answers "roughly how far?" perfectly well and answers
 * "what should this cost?" wrongly by 30–60% in Lagos, in one direction, on
 * every single order. The last tests in this file are the ones that would catch
 * somebody quietly filling that hole in — and it is a tempting hole to fill,
 * because a haversine step always returns an answer.
 */
function lagosRoute(): RouteQuery
{
    return new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
        TravelMode::TwoWheeler,
    );
}

/**
 * A provider that is reachable and failing — the realistic outage.
 *
 * Deliberately not a registry that throws on resolution: a provider that
 * answers with an error is the case the circuit breaker and telemetry are built
 * for, and it exercises the guard rather than stepping around it.
 */
function withFailingRoutingProvider(): void
{
    $failing = new class () implements DistanceMatrixProvider, GeocodingProvider, PlacesProvider, RoutingProvider {
        public function name(): string
        {
            return 'failing';
        }

        public function geocode(EruoFood\Geo\Application\DTO\GeocodeQuery $query): EruoFood\Geo\Application\DTO\GeocodeResult
        {
            throw GeoProviderUnavailable::because('down');
        }

        public function reverseGeocode(Coordinates $coordinates, ?string $language = null): EruoFood\Geo\Application\DTO\GeocodeResult
        {
            throw GeoProviderUnavailable::because('down');
        }

        public function route(RouteQuery $query): Route
        {
            throw GeoProviderUnavailable::because('down');
        }

        public function matrix(array $origins, array $destinations, TravelMode $travelMode): EruoFood\Geo\Application\DTO\RouteMatrixResult
        {
            throw GeoProviderUnavailable::because('down');
        }

        public function autocomplete(string $input, ?Coordinates $bias = null, ?string $countryCode = null): array
        {
            throw GeoProviderUnavailable::because('down');
        }
    };

    app()->instance(GeoProviderRegistry::class, new class ($failing) implements GeoProviderRegistry {
        public function __construct(private readonly object $provider)
        {
        }

        public function geocoding(?string $countryCode = null): GeocodingProvider
        {
            return $this->provider;
        }

        public function routing(?string $countryCode = null): RoutingProvider
        {
            return $this->provider;
        }

        public function distanceMatrix(?string $countryCode = null): DistanceMatrixProvider
        {
            return $this->provider;
        }

        public function places(?string $countryCode = null): PlacesProvider
        {
            return $this->provider;
        }
    });

    app()->forgetInstance(RoutingService::class);
}

/** Capture published events without a queue, so the health signal is observable. */
function capturingEventBus(): object
{
    $bus = new class () implements EventBus {
        /** @var list<DomainEvent> */
        public array $published = [];

        public function publish(DomainEvent ...$events): void
        {
            foreach ($events as $event) {
                $this->published[] = $event;
            }
        }
    };

    app()->instance(EventBus::class, $bus);
    app()->forgetInstance(RoutingService::class);

    return $bus;
}

/** Empty the hot cache, leaving the durable route table intact. */
function flushHotCache(): void
{
    Cache::flush();
}

// -------------------------------------------------------- step 1: fresh route

it('measures a fresh route and stores it in both caches', function (): void {
    $route = app(RoutingService::class)->attempt(lagosRoute());

    expect($route)->not->toBeNull()
        ->and($route->source)->toBe(RouteSource::Provider)
        ->and($route->isBillable(new DateTimeImmutable(), 21_600))->toBeTrue()
        // Durable as well as hot, so a Redis flush does not lose it.
        ->and(RouteCacheModel::query()->count())->toBe(1);
});

/**
 * Regression: the durable cache key must fit its column.
 *
 * `cache_key` was `varchar(64)` — exactly a sha256 — while the key carries a
 * `route:` namespace prefix as well, making it 70 characters. SQLite truncates
 * silently and let every test pass; PostgreSQL rejects the row. In production
 * that meant every single route store failed, giving a permanent
 * hundred-percent cache miss, a bill to match, and — because PostgreSQL
 * poisons a transaction after a failed statement — a broken request for
 * anything that had already opened one.
 *
 * Asserted by reading the stored key back rather than by trusting the write,
 * because a truncating engine reports success either way.
 */
it('stores the full cache key without truncating it', function (): void {
    app(RoutingService::class)->attempt(lagosRoute());

    $key = RouteCacheModel::query()->value('cache_key');

    expect($key)->toStartWith('route:')
        // The prefix plus a full sha256 digest, intact.
        ->and(strlen((string) $key))->toBe(70)
        ->and(app(RouteCacheRepository::class)->findByKeyRegardlessOfAge((string) $key))->not->toBeNull();
});

it('serves a repeat request from cache without calling the provider again', function (): void {
    $service = app(RoutingService::class);

    $first = $service->attempt(lagosRoute());
    $second = $service->attempt(lagosRoute());

    expect($first->distanceMetres)->toBe($second->distanceMetres)
        ->and($second->source)->toBe(RouteSource::Cache)
        // One billable call, one cache hit — the point of the cache, proved
        // rather than assumed.
        ->and(ProviderRequestModel::query()->where('served_from_cache', false)->count())->toBe(1)
        ->and(ProviderRequestModel::query()->where('served_from_cache', true)->count())->toBe(1);
});

/**
 * Two requests from opposite ends of the same building should share an answer,
 * or the cache would miss on almost every real request.
 */
it('shares a cached route between nearby origins', function (): void {
    $service = app(RoutingService::class);

    $service->attempt(lagosRoute());

    // About four metres away — inside the key's rounding.
    $nearby = new RouteQuery(
        new Coordinates(6.60183, 3.35152),
        new Coordinates(6.4281, 3.4219),
        TravelMode::TwoWheeler,
    );

    expect($service->attempt($nearby)->source)->toBe(RouteSource::Cache)
        ->and(RouteCacheModel::query()->count())->toBe(1);
});

it('does not share a cached route between travel modes', function (): void {
    $service = app(RoutingService::class);

    $service->attempt(lagosRoute());
    $driving = $service->attempt(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
        TravelMode::Driving,
    ));

    // A motorbike and a car do not take the same path through Lagos.
    expect($driving->source)->toBe(RouteSource::Provider)
        ->and(RouteCacheModel::query()->count())->toBe(2);
});

// ---------------------------------------- step 2: the durable table after a flush

/**
 * The moment this table exists for: Redis restarted, and a customer is checking
 * out. A route measured an hour ago is still a defensible basis for a fee.
 */
it('serves from the durable table when the hot cache is gone', function (): void {
    app(RoutingService::class)->attempt(lagosRoute());
    flushHotCache();

    $route = app(RoutingService::class)->attempt(lagosRoute());

    expect($route->source)->toBe(RouteSource::Cache)
        ->and($route->isBillable(new DateTimeImmutable(), 21_600))->toBeTrue()
        // Still one provider call in total.
        ->and(ProviderRequestModel::query()->where('served_from_cache', false)->count())->toBe(1);
});

// ------------------------------------------------- step 3: stale cached route

/**
 * Past the grace period the row stops being served on the hot path, but it is
 * still a real measurement — so when the provider is down it is offered to the
 * caller, explicitly too old to charge for. That distinction is the whole
 * design: the caller gets the best available answer *and* the truth about it.
 */
it('offers a stale stored route when the provider fails, marked unbillable', function (): void {
    app(RoutingService::class)->attempt(lagosRoute());

    // Age the stored measurement past the six-hour grace, and empty Redis.
    RouteCacheModel::query()->update(['calculated_at' => new DateTimeImmutable('-8 hours')]);
    flushHotCache();

    withFailingRoutingProvider();

    $route = app(RoutingService::class)->attempt(lagosRoute());

    expect($route)->not->toBeNull()
        ->and($route->source)->toBe(RouteSource::Cache)
        ->and($route->distanceMetres)->toBeGreaterThan(0)
        // Offered, and the caller is told plainly it may not be billed.
        ->and($route->isBillable(new DateTimeImmutable(), 21_600))->toBeFalse()
        ->and($route->ageSeconds(new DateTimeImmutable()))->toBeGreaterThan(21_600);
});

// --------------------------------------------------- step 4: honest refusal

it('returns nothing rather than a guess when there is no route and no history', function (): void {
    withFailingRoutingProvider();

    expect(app(RoutingService::class)->attempt(lagosRoute()))->toBeNull();
});

it('raises rather than returning a guess when a caller demands a route', function (): void {
    withFailingRoutingProvider();

    expect(fn () => app(RoutingService::class)->route(lagosRoute()))
        ->toThrow(GeoProviderUnavailable::class);
});

it('publishes a failure event so a degrading provider is visible before customers notice', function (): void {
    withFailingRoutingProvider();
    $bus = capturingEventBus();

    app(RoutingService::class)->attempt(lagosRoute());

    $failures = array_values(array_filter(
        $bus->published,
        static fn (DomainEvent $e): bool => $e instanceof RouteCalculationFailed,
    ));

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->reason)->toBe('GEO_PROVIDER_UNAVAILABLE')
        ->and($failures[0]->travelMode)->toBe('two_wheeler');
});

it('records the failed call in the cost and health ledger', function (): void {
    withFailingRoutingProvider();

    app(RoutingService::class)->attempt(lagosRoute());

    $row = ProviderRequestModel::query()->where('capability', 'route')->first();

    expect($row)->not->toBeNull()
        ->and($row->succeeded)->toBeFalse()
        // A normalised code, never the provider's own message — that quotes the
        // request, and a request can name a customer's street.
        ->and($row->failure_code)->toBe('PROVIDER_UNAVAILABLE');
});

// ------------------------------------------- the step that must not exist

/**
 * The assertion this whole file exists for. If somebody adds a haversine step
 * to the chain, this fails.
 */
it('never substitutes a straight-line distance for a routed one', function (): void {
    $query = lagosRoute();
    $straightLine = (int) round(Haversine::metres($query->origin, $query->destination));

    $routed = app(RoutingService::class)->attempt($query);

    expect($routed->source)->not->toBe(RouteSource::Haversine)
        ->and($routed->distanceMetres)->toBeGreaterThan($straightLine);

    // And with the provider down and nothing stored, the answer is nothing —
    // not the straight line, which is always available and always wrong.
    RouteCacheModel::query()->delete();
    flushHotCache();
    withFailingRoutingProvider();

    expect(app(RoutingService::class)->attempt($query))->toBeNull();
});

it('refuses to bill any route whose source is haversine, at any age', function (): void {
    $now = new DateTimeImmutable();

    $guess = new Route(
        origin: new Coordinates(6.6018, 3.3515),
        destination: new Coordinates(6.4281, 3.4219),
        distanceMetres: 19_000,
        durationSeconds: 2_800,
        travelMode: TravelMode::TwoWheeler,
        source: RouteSource::Haversine,
        provider: 'haversine',
        calculatedAt: $now,
    );

    expect($guess->isBillable($now, 21_600))->toBeFalse()
        ->and($guess->isBillable($now, PHP_INT_MAX))->toBeFalse()
        ->and(RouteSource::Haversine->isBillable())->toBeFalse();
});
