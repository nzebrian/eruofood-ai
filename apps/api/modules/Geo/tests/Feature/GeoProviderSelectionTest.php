<?php

declare(strict_types=1);

use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Infrastructure\Provider\ConfigGeoProviderRegistry;
use EruoFood\Geo\Infrastructure\Provider\Google\GoogleMapsProvider;
use EruoFood\Geo\Infrastructure\Provider\Mock\MockMapProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Which provider gets chosen, and — the point of the first test — which one
 * never does.
 *
 * M24 taught this the hard way: a `.env.example` assignment is an override, not
 * a default, and it silently defeated `env('X', APP_ENV === 'testing' ? …)`
 * logic that looked airtight. The consequence there was twelve failing tests.
 * The consequence here would be a test suite quietly making billable calls to
 * Google on every CI run, which is the kind of thing discovered by invoice.
 */
it('never resolves a live provider in the test environment', function (): void {
    $registry = app(GeoProviderRegistry::class);

    expect($registry->geocoding())->toBeInstanceOf(MockMapProvider::class)
        ->and($registry->routing())->toBeInstanceOf(MockMapProvider::class)
        ->and($registry->places())->toBeInstanceOf(MockMapProvider::class)
        ->and($registry->distanceMatrix())->toBeInstanceOf(MockMapProvider::class)
        ->and(config('geo.routing.geocoding.default'))->toBe('mock')
        ->and(config('geo.routing.routing.default'))->toBe('mock');
});

/**
 * The belt to the previous test's braces: even if the routing table were
 * misconfigured, an actual outbound HTTP call during the suite would be caught
 * here rather than on a bill.
 */
it('makes no outbound request when the suite geocodes', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    app(GeoProviderRegistry::class)->geocoding()->geocode(
        new EruoFood\Geo\Application\DTO\GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'),
    );

    Http::assertNothingSent();
});

it('resolves the configured provider per capability', function (): void {
    config()->set('geo.routing.geocoding.default', 'google');
    config()->set('geo.providers.google.server_key', 'test-key');

    // The registry is a singleton, so a config change mid-test needs a fresh
    // instance — the same reason a deployment must restart rather than expect a
    // provider swap to take effect on the next request.
    app()->forgetInstance(GeoProviderRegistry::class);

    expect(app(GeoProviderRegistry::class)->geocoding())->toBeInstanceOf(GoogleMapsProvider::class)
        // Routing was not changed, so it stays on the mock — capabilities are
        // resolved independently.
        ->and(app(GeoProviderRegistry::class)->routing())->toBeInstanceOf(MockMapProvider::class);
});

/**
 * The seam that makes Google replaceable per market: a country override, with
 * no change to any service, controller or domain object.
 */
it('prefers a country-specific provider over the default', function (): void {
    $registry = new ConfigGeoProviderRegistry(
        factories: [
            'mock' => fn (): MockMapProvider => new MockMapProvider(),
            'google' => fn (): GoogleMapsProvider => new GoogleMapsProvider(new HttpFactory(), ['server_key' => 'k']),
        ],
        routing: [
            'geocoding' => ['default' => 'mock', 'by_country' => ['KE' => 'google']],
        ],
    );

    expect($registry->geocoding('NG'))->toBeInstanceOf(MockMapProvider::class)
        ->and($registry->geocoding('KE'))->toBeInstanceOf(GoogleMapsProvider::class)
        // Case-insensitive, because a country code arrives from a request body
        // as often as from configuration.
        ->and($registry->geocoding('ke'))->toBeInstanceOf(GoogleMapsProvider::class);
});

/**
 * Returning null and letting the caller cope is how a delivery ends up priced
 * against a distance nobody measured. An unresolvable capability is loud.
 */
it('raises rather than returning nothing when no provider is configured', function (): void {
    $registry = new ConfigGeoProviderRegistry(factories: [], routing: []);

    expect(fn () => $registry->geocoding())->toThrow(GeoProviderUnavailable::class)
        ->and(fn () => $registry->routing())->toThrow(GeoProviderUnavailable::class);
});

it('raises when the configured provider cannot do what was asked of it', function (): void {
    $geocodeOnly = new class () implements EruoFood\Geo\Application\Port\GeocodingProvider {
        public function name(): string
        {
            return 'partial';
        }

        public function geocode(EruoFood\Geo\Application\DTO\GeocodeQuery $query): EruoFood\Geo\Application\DTO\GeocodeResult
        {
            throw new RuntimeException('not needed');
        }

        public function reverseGeocode(EruoFood\Geo\Domain\ValueObject\Coordinates $coordinates, ?string $language = null): EruoFood\Geo\Application\DTO\GeocodeResult
        {
            throw new RuntimeException('not needed');
        }
    };

    $registry = new ConfigGeoProviderRegistry(
        factories: ['partial' => fn (): object => $geocodeOnly],
        routing: [
            'geocoding' => ['default' => 'partial'],
            'routing' => ['default' => 'partial'],
        ],
    );

    expect($registry->geocoding())->toBe($geocodeOnly)
        // A provider that geocodes but cannot route must not be handed back as
        // a router; the failure belongs at resolution, not at the call site.
        ->and(fn () => $registry->routing())->toThrow(GeoProviderUnavailable::class);
});
