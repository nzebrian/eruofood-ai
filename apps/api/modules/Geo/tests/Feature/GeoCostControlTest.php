<?php

declare(strict_types=1);

use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\Port\CircuitBreakerPort;
use EruoFood\Geo\Application\Port\GeoCache;
use EruoFood\Geo\Application\Port\GeoRateLimiter;
use EruoFood\Geo\Application\Service\GeocodingService;
use EruoFood\Geo\Application\Service\ProviderGuard;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Infrastructure\Cache\RedisGeoCache;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\ProviderRequestModel;
use EruoFood\Geo\Infrastructure\Provider\CircuitBreaker;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * The controls that stand between a mapping integration and an unbounded bill.
 *
 * Every capability here bills per request, and the failure mode is not a crash
 * but an invoice — which means nobody notices until the month ends. A mobile
 * client stuck in a retry loop is a financial incident with no error log.
 */

// ------------------------------------------------------------------- caching

it('pays for a geocode once and serves the repeat from cache', function (): void {
    $service = app(GeocodingService::class);
    $query = new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG');

    $first = $service->geocode($query);
    $second = $service->geocode($query);

    expect($second->coordinates->toKey())->toBe($first->coordinates->toKey())
        ->and($second->address->formatted)->toBe($first->address->formatted)
        ->and(ProviderRequestModel::query()->where('served_from_cache', false)->count())->toBe(1)
        ->and(ProviderRequestModel::query()->where('served_from_cache', true)->count())->toBe(1);
});

it('treats differently written forms of one address as one cache entry', function (): void {
    $service = app(GeocodingService::class);

    $service->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));
    $service->geocode(new GeocodeQuery('  12   ALLEN Avenue,   Ikeja  ', 'ng'));

    expect(ProviderRequestModel::query()->where('served_from_cache', false)->count())->toBe(1);
});

/**
 * Rounding is what makes reverse geocoding cacheable at all: two device fixes
 * from the same doorway differ in the sixth decimal and would otherwise be two
 * keys for one answer.
 */
it('shares a reverse geocode between points a metre apart', function (): void {
    $service = app(GeocodingService::class);

    $service->reverseGeocode(new Coordinates(6.4550000, 3.3841000));
    $service->reverseGeocode(new Coordinates(6.4550001, 3.3841002));

    expect(ProviderRequestModel::query()->where('capability', 'reverse_geocode')->where('served_from_cache', false)->count())->toBe(1);
});

/**
 * Autocomplete is the most expensive capability per useful outcome: a
 * keystroke-per-request client makes twenty billable calls to save one address.
 */
it('does not spend a call on an input too short to suggest anything', function (): void {
    $service = app(GeocodingService::class);

    expect($service->autocomplete('Al'))->toBe([])
        ->and($service->autocomplete(''))->toBe([])
        ->and(ProviderRequestModel::query()->count())->toBe(0);

    expect($service->autocomplete('Allen'))->not->toBeEmpty()
        ->and(ProviderRequestModel::query()->where('served_from_cache', false)->count())->toBe(1);
});

it('serves repeated autocomplete keystrokes from cache', function (): void {
    $service = app(GeocodingService::class);

    $first = $service->autocomplete('Allen Avenue');
    $second = $service->autocomplete('Allen Avenue');

    expect($second)->toHaveCount(count($first))
        ->and($second[0]->providerPlaceId)->toBe($first[0]->providerPlaceId)
        ->and(ProviderRequestModel::query()->where('capability', 'autocomplete')->where('served_from_cache', false)->count())->toBe(1);
});

/**
 * The cache exists to save money and latency. An unreachable Redis should make
 * geocoding expensive, not broken — a throwing cache would take checkout down
 * to protect a cost optimisation.
 */
it('treats a broken cache as a miss rather than an error', function (): void {
    $exploding = new class () implements CacheRepository {
        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function put($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function has($key): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function many(array $keys): array
        {
            throw new RuntimeException('redis is gone');
        }

        public function putMany(array $values, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function increment($key, $value = 1): int|bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function decrement($key, $value = 1): int|bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function sear($key, Closure $callback): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function pull($key, $default = null): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function add($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function getStore(): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function getMultiple($keys, $default = null): iterable
        {
            throw new RuntimeException('redis is gone');
        }

        public function setMultiple($values, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function deleteMultiple($keys): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function clear(): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function set($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }

        public function delete($key): bool
        {
            throw new RuntimeException('redis is gone');
        }
    };

    app()->instance(GeoCache::class, new RedisGeoCache($exploding, 'geo'));
    app()->forgetInstance(GeocodingService::class);

    $result = app(GeocodingService::class)->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));

    // Answered, at full price, rather than failing.
    expect($result->coordinates)->toBeInstanceOf(Coordinates::class);
});

// --------------------------------------------------------------- daily quota

it('stops spending once the platform daily budget is gone', function (): void {
    $guard = new ProviderGuard(
        app(CircuitBreakerPort::class),
        app(EruoFood\Geo\Application\Port\ProviderTelemetry::class),
        app(EruoFood\Shared\Domain\EventBus::class),
        dailyQuota: 2,
        failureThreshold: 5,
    );

    $guard->call('mock', 'geocode', fn (): string => 'first');
    $guard->call('mock', 'geocode', fn (): string => 'second');

    expect(fn () => $guard->call('mock', 'geocode', fn (): string => 'third'))
        ->toThrow(GeoQuotaExceeded::class);

    // The refusal itself is recorded, so a platform hitting its ceiling is
    // visible rather than merely quiet.
    expect(ProviderRequestModel::query()->where('failure_code', 'QUOTA_EXCEEDED')->count())->toBe(1);
});

it('does not count cache hits against the billable budget', function (): void {
    $service = app(GeocodingService::class);
    $query = new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG');

    $service->geocode($query);
    $service->geocode($query);
    $service->geocode($query);

    expect(app(EruoFood\Geo\Application\Port\ProviderTelemetry::class)->billableCallsToday())->toBe(1);
});

// ----------------------------------------------------------- circuit breaker

it('opens the circuit after repeated failures and then refuses immediately', function (): void {
    $breaker = new CircuitBreaker(Cache::store(), failureThreshold: 3, openSeconds: 60);
    $guard = new ProviderGuard(
        $breaker,
        app(EruoFood\Geo\Application\Port\ProviderTelemetry::class),
        app(EruoFood\Shared\Domain\EventBus::class),
        dailyQuota: 0,
        failureThreshold: 3,
    );

    $calls = 0;
    $failing = function () use (&$calls): string {
        $calls++;

        throw GeoProviderUnavailable::because('down');
    };

    foreach (range(1, 3) as $ignored) {
        try {
            $guard->call('mock', 'route', $failing);
        } catch (GeoProviderUnavailable) {
            // expected
        }
    }

    expect($breaker->isOpen('mock:route'))->toBeTrue();

    // The fourth call never reaches the provider: the caller gets to its
    // fallback in microseconds instead of waiting out another timeout, and
    // does not pay for a call that was not going to work.
    expect(fn () => $guard->call('mock', 'route', $failing))->toThrow(GeoProviderUnavailable::class)
        ->and($calls)->toBe(3)
        ->and(ProviderRequestModel::query()->where('failure_code', 'CIRCUIT_OPEN')->count())->toBe(1);
});

/**
 * The distinction that keeps geocoding available: an address that does not
 * exist is the provider working correctly. Counting it as a failure would let a
 * run of typos open the circuit and take geocoding down for everybody.
 */
it('does not count a not-found address towards opening the circuit', function (): void {
    $breaker = new CircuitBreaker(Cache::store(), failureThreshold: 2, openSeconds: 60);
    $guard = new ProviderGuard(
        $breaker,
        app(EruoFood\Geo\Application\Port\ProviderTelemetry::class),
        app(EruoFood\Shared\Domain\EventBus::class),
        dailyQuota: 0,
        failureThreshold: 2,
    );

    foreach (range(1, 5) as $ignored) {
        try {
            $guard->call('mock', 'geocode', fn () => throw GeoAddressNotFound::forQuery());
        } catch (GeoAddressNotFound) {
            // expected
        }
    }

    expect($breaker->isOpen('mock:geocode'))->toBeFalse()
        ->and($breaker->consecutiveFailures('mock:geocode'))->toBe(0)
        // Recorded as a successful call that found nothing, which is what it is.
        ->and(ProviderRequestModel::query()->where('failure_code', 'NOT_FOUND')->where('succeeded', true)->count())->toBe(5);
});

it('closes the circuit again after a success', function (): void {
    $breaker = new CircuitBreaker(Cache::store(), failureThreshold: 2, openSeconds: 60);

    $breaker->recordFailure('mock:route');
    $breaker->recordFailure('mock:route');

    expect($breaker->isOpen('mock:route'))->toBeTrue();

    $breaker->recordSuccess('mock:route');

    expect($breaker->isOpen('mock:route'))->toBeFalse()
        ->and($breaker->consecutiveFailures('mock:route'))->toBe(0);
});

// ------------------------------------------------------------- rate limiting

it('limits a caller to its per-minute allowance', function (): void {
    $limiter = app(GeoRateLimiter::class);

    foreach (range(1, 3) as $ignored) {
        expect($limiter->attempt('user-1:geocode', 3))->toBeTrue();
    }

    expect($limiter->attempt('user-1:geocode', 3))->toBeFalse()
        ->and($limiter->remaining('user-1:geocode', 3))->toBe(0)
        // One caller's exhausted allowance does not affect anybody else's.
        ->and($limiter->attempt('user-2:geocode', 3))->toBeTrue();
});
