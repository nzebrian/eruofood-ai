<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider;

use EruoFood\Geo\Application\Port\CircuitBreakerPort;
use EruoFood\Geo\Application\Port\GeoCache;
use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Application\Port\GeoRateLimiter;
use EruoFood\Geo\Application\Port\ProviderTelemetry;
use EruoFood\Geo\Application\Service\AddressBookService;
use EruoFood\Geo\Application\Service\DeliveryDistanceService;
use EruoFood\Geo\Application\Service\DeliveryZoneService;
use EruoFood\Geo\Application\Service\GeocodingService;
use EruoFood\Geo\Application\Service\MerchantLocationService;
use EruoFood\Geo\Application\Service\ProviderGuard;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Application\Service\RoutingService;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
use EruoFood\Geo\Domain\Address\CustomerAddressRepository;
use EruoFood\Geo\Domain\Location\LocationRepository;
use EruoFood\Geo\Domain\Rider\RiderLocationRepository;
use EruoFood\Geo\Domain\Route\RouteCacheRepository;
use EruoFood\Geo\Domain\Zone\DeliveryZoneRepository;
use EruoFood\Geo\Infrastructure\Cache\RedisGeoCache;
use EruoFood\Geo\Infrastructure\Event\KybLocationSubscriber;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentCustomerAddressRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentDeliveryZoneRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentLocationRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentRiderLocationRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentRouteCacheRepository;
use EruoFood\Geo\Infrastructure\Provider\Google\GoogleMapsProvider;
use EruoFood\Geo\Infrastructure\Provider\Mock\MockMapProvider;
use EruoFood\Geo\Infrastructure\RateLimit\CacheGeoRateLimiter;
use EruoFood\Geo\Interface\Http\Controller\GeoPresenter;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Maps & Geolocation context.
 *
 * Provider factories are registered as lazy closures, so configuring Google
 * does not instantiate an HTTP client and an unconfigured provider is only
 * missed when something actually asks for it. Adding Mapbox or a regional
 * provider is an entry in the factory map plus an adapter — nothing in the
 * domain moves.
 *
 * Every tunable is read here rather than inside a service, so the services stay
 * constructible in a test without a config repository and so an operator has
 * one file to read.
 */
final class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/geo.php', 'geo');
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/delivery.php', 'delivery');

        $this->registerRepositories();
        $this->registerInfrastructure();
        $this->registerProviderRegistry();
        $this->registerServices();
        $this->registerPresentation();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        // Geocodes a business's registered address once M24 approves its KYB —
        // filling the latitude/longitude columns M24 created and could not
        // populate, because there was no geocoder until now.
        $this->app->make(KybLocationSubscriber::class)->register($this->app->make(Dispatcher::class));
    }

    private function registerRepositories(): void
    {
        $this->app->bind(LocationRepository::class, EloquentLocationRepository::class);
        $this->app->bind(CustomerAddressRepository::class, EloquentCustomerAddressRepository::class);
        $this->app->bind(RiderLocationRepository::class, EloquentRiderLocationRepository::class);
        $this->app->bind(DeliveryZoneRepository::class, EloquentDeliveryZoneRepository::class);

        $this->app->bind(RouteCacheRepository::class, fn (): EloquentRouteCacheRepository => new EloquentRouteCacheRepository(
            (int) config('geo.cache.stale_route_grace', 21_600),
        ));
    }

    private function registerInfrastructure(): void
    {
        $this->app->singleton(GeoCache::class, fn ($app): RedisGeoCache => new RedisGeoCache(
            $app->make(CacheRepository::class),
            (string) config('geo.cache.prefix', 'geo'),
            (bool) config('geo.cache.enabled', true),
        ));

        $this->app->singleton(ProviderTelemetry::class, EloquentProviderTelemetry::class);

        $this->app->singleton(CircuitBreaker::class, fn ($app): CircuitBreaker => new CircuitBreaker(
            $app->make(CacheRepository::class),
            (int) config('geo.circuit_breaker.failure_threshold', 5),
            (int) config('geo.circuit_breaker.open_seconds', 60),
            (bool) config('geo.circuit_breaker.enabled', true),
        ));

        $this->app->singleton(CircuitBreakerPort::class, CircuitBreaker::class);

        $this->app->singleton(GeoRateLimiter::class, fn ($app): CacheGeoRateLimiter => new CacheGeoRateLimiter(
            $app->make(RateLimiter::class),
        ));
    }

    /**
     * The seam that keeps Google replaceable.
     *
     * Nothing above this line names a mapping vendor. The registry is handed a
     * map of `name => factory` and a routing table read from configuration, so
     * swapping Google for a regional provider in one market is a config edit
     * plus an adapter class — not a change to any service, controller or domain
     * object.
     */
    private function registerProviderRegistry(): void
    {
        $this->app->singleton(GeoProviderRegistry::class, function ($app): ConfigGeoProviderRegistry {
            /** @var array<string, array{default?: string, by_country?: array<string, string>}> $routing */
            $routing = config('geo.routing', []);

            return new ConfigGeoProviderRegistry(
                factories: [
                    'mock' => fn (): MockMapProvider => new MockMapProvider(
                        (array) config('geo.providers.mock', []),
                    ),
                    'google' => fn (): GoogleMapsProvider => new GoogleMapsProvider(
                        $app->make(HttpFactory::class),
                        (array) config('geo.providers.google', []),
                    ),
                ],
                routing: $routing,
            );
        });
    }

    private function registerServices(): void
    {
        $this->app->singleton(ProviderGuard::class, fn ($app): ProviderGuard => new ProviderGuard(
            $app->make(CircuitBreakerPort::class),
            $app->make(ProviderTelemetry::class),
            $app->make(EventBus::class),
            (int) config('geo.limits.provider_daily_quota', 50_000),
            (int) config('geo.circuit_breaker.failure_threshold', 5),
        ));

        $this->app->singleton(GeocodingService::class, fn ($app): GeocodingService => new GeocodingService(
            $app->make(GeoProviderRegistry::class),
            $app->make(GeoCache::class),
            $app->make(ProviderGuard::class),
            (int) config('geo.cache.geocode_ttl', 2_592_000),
            (int) config('geo.cache.reverse_geocode_ttl', 604_800),
            (int) config('geo.cache.autocomplete_ttl', 3_600),
            (int) config('geo.cache.coordinate_precision', 5),
        ));

        $this->app->singleton(RoutingService::class, fn ($app): RoutingService => new RoutingService(
            $app->make(GeoProviderRegistry::class),
            $app->make(GeoCache::class),
            $app->make(RouteCacheRepository::class),
            $app->make(ProviderGuard::class),
            $app->make(EventBus::class),
            (int) config('geo.cache.route_ttl', 86_400),
            (int) config('geo.cache.route_traffic_ttl', 300),
            (int) config('geo.cache.matrix_ttl', 3_600),
            (int) config('geo.cache.route_coordinate_precision', 4),
        ));

        /*
        | The pricing switch, read here.
        |
        | `delivery.routing_pricing.enabled` decides whether customers are
        | billed on measured road distance or on the pre-M25 straight line. It
        | ships false. Because it is resolved per construction rather than
        | compiled in, rollback is a configuration change — no deploy, no
        | migration.
        */
        $this->app->singleton(DeliveryDistanceService::class, fn ($app): DeliveryDistanceService => new DeliveryDistanceService(
            $app->make(RoutingService::class),
            (int) config('geo.cache.stale_route_grace', 21_600),
            (bool) config('delivery.routing_pricing.enabled', false),
            (float) config('delivery.routing_pricing.max_detour_ratio', 4.0),
            (string) config('geo.defaults.travel_mode', 'two_wheeler'),
        ));

        $this->app->singleton(DeliveryDistanceProvider::class, DeliveryDistanceService::class);

        $this->app->singleton(AddressBookService::class);
        $this->app->singleton(MerchantLocationService::class);
        $this->app->singleton(DeliveryZoneService::class);

        $this->app->singleton(RiderLocationService::class, fn ($app): RiderLocationService => new RiderLocationService(
            $app->make(RiderLocationRepository::class),
            $app->make(GeoRateLimiter::class),
            $app->make(EventBus::class),
            (int) config('geo.privacy.rider_location_stale_seconds', 300),
            (int) config('geo.limits.rider_location_per_minute', 30),
        ));
    }

    private function registerPresentation(): void
    {
        // The one place that decides how much of a location leaves the
        // building. Per-controller shaping is how a private field ends up on a
        // public endpoint one refactor later.
        $this->app->singleton(GeoPresenter::class, fn (): GeoPresenter => new GeoPresenter(
            (int) config('geo.privacy.public_coordinate_precision', 3),
        ));
    }
}
