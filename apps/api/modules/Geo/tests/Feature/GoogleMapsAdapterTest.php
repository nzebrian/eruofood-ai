<?php

declare(strict_types=1);

use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Infrastructure\Provider\Google\GoogleMapsProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/**
 * The Google adapter, driven entirely by recorded responses.
 *
 * **No test in this suite calls Google.** Every response below is a fixture
 * shaped like the real API's, faked at the HTTP client. A suite that made live
 * calls would be slow, flaky, dependent on somebody else's uptime, billable per
 * run, and would need a production key in CI — five separate reasons before
 * considering that it still would not exercise the failure paths, which are the
 * part that matters.
 *
 * The credential assertions are the ones to read first: a key in a query string
 * ends up in access logs, proxy logs and exception traces.
 */
const GEO_TEST_KEY = 'test-server-key-not-a-real-credential';

/** @param array<string, mixed> $overrides */
function googleProvider(HttpFactory $http, array $overrides = []): GoogleMapsProvider
{
    return new GoogleMapsProvider($http, array_merge([
        'server_key' => GEO_TEST_KEY,
        'geocoding_url' => 'https://maps.googleapis.com/maps/api/geocode/json',
        'routes_url' => 'https://routes.googleapis.com/directions/v2:computeRoutes',
        'matrix_url' => 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
        'places_url' => 'https://places.googleapis.com/v1/places:autocomplete',
        'timeout_seconds' => 2,
        'retry_attempts' => 1,
        'retry_delay_ms' => 0,
        'region_bias' => 'ng',
        'language' => 'en',
    ], $overrides));
}

/** @return array<string, mixed> */
function geocodeFixture(string $locationType = 'ROOFTOP'): array
{
    return [
        'status' => 'OK',
        'results' => [[
            'formatted_address' => '12 Allen Ave, Ikeja 101233, Lagos, Nigeria',
            'place_id' => 'ChIJtest12345',
            'geometry' => [
                'location' => ['lat' => 6.6018, 'lng' => 3.3515],
                'location_type' => $locationType,
            ],
            'address_components' => [
                ['long_name' => '12', 'short_name' => '12', 'types' => ['street_number']],
                ['long_name' => 'Allen Avenue', 'short_name' => 'Allen Ave', 'types' => ['route']],
                ['long_name' => 'Opebi', 'short_name' => 'Opebi', 'types' => ['neighborhood']],
                ['long_name' => 'Ikeja', 'short_name' => 'Ikeja', 'types' => ['locality']],
                ['long_name' => 'Ikeja', 'short_name' => 'Ikeja', 'types' => ['administrative_area_level_2']],
                ['long_name' => 'Lagos', 'short_name' => 'LA', 'types' => ['administrative_area_level_1']],
                ['long_name' => 'Nigeria', 'short_name' => 'NG', 'types' => ['country']],
                ['long_name' => '101233', 'short_name' => '101233', 'types' => ['postal_code']],
            ],
        ]],
    ];
}

// ------------------------------------------------------------- credentials

/**
 * The assertion that matters most in this file. Query strings are logged by web
 * servers, proxies and error trackers; a key that reaches one of those is a key
 * that has to be rotated.
 */
it('sends the key as a header on the Routes API, never in the URL', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        'routes' => [['distanceMeters' => 26_000, 'staticDuration' => '3900s']],
    ])]);

    googleProvider($http)->route(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
        TravelMode::TwoWheeler,
    ));

    $http->assertSent(function (Request $request): bool {
        expect($request->url())->not->toContain(GEO_TEST_KEY)
            ->and($request->header('X-Goog-Api-Key'))->toBe([GEO_TEST_KEY]);

        return true;
    });
});

it('asks only for the fields it uses, because the Routes API bills by response tier', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        'routes' => [['distanceMeters' => 26_000, 'staticDuration' => '3900s']],
    ])]);

    googleProvider($http)->route(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
        TravelMode::TwoWheeler,
    ));

    $http->assertSent(function (Request $request): bool {
        $mask = $request->header('X-Goog-FieldMask')[0] ?? '';

        expect($mask)->toContain('routes.distanceMeters')
            ->and($mask)->toContain('routes.duration')
            // Legs and steps are the expensive tier and nothing reads them.
            ->and($mask)->not->toContain('routes.legs');

        return true;
    });
});

it('refuses to call the provider at all when no key is configured', function (): void {
    $http = new HttpFactory();
    $http->fake();

    expect(fn () => googleProvider($http, ['server_key' => null])->geocode(new GeocodeQuery('12 Allen Avenue', 'NG')))
        ->toThrow(GeoProviderUnavailable::class);

    // Not merely rejected — never sent. An unauthenticated call would come back
    // as REQUEST_DENIED and read like an outage rather than a deployment nobody
    // finished configuring.
    $http->assertNothingSent();
});

// --------------------------------------------------------------- geocoding

it('translates a Google geocode into the platform vocabulary', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(geocodeFixture())]);

    $result = googleProvider($http)->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));

    expect($result->coordinates->latitude)->toBe(6.6018)
        ->and($result->precision)->toBe(LocationPrecision::Rooftop)
        ->and($result->provider)->toBe('google')
        ->and($result->providerPlaceId)->toBe('ChIJtest12345')
        ->and($result->address->line1)->toBe('12 Allen Avenue')
        ->and($result->address->district)->toBe('Opebi')
        ->and($result->address->locality)->toBe('Ikeja')
        // Country-neutral names: a state here, a province elsewhere.
        ->and($result->address->adminArea)->toBe('Lagos')
        ->and($result->address->subAdminArea)->toBe('Ikeja')
        ->and($result->address->countryCode)->toBe('NG')
        ->and($result->address->postalCode)->toBe('101233');
});

it('maps every Google location type onto a precision the domain understands', function (string $googleType, LocationPrecision $expected): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(geocodeFixture($googleType))]);

    expect(googleProvider($http)->geocode(new GeocodeQuery('somewhere', 'NG'))->precision)->toBe($expected);
})->with([
    ['ROOFTOP', LocationPrecision::Rooftop],
    ['RANGE_INTERPOLATED', LocationPrecision::RangeInterpolated],
    ['GEOMETRIC_CENTER', LocationPrecision::GeometricCentre],
    ['APPROXIMATE', LocationPrecision::Approximate],
    ['SOMETHING_NEW', LocationPrecision::Unknown],
]);

/**
 * The classic integration bug this closes: the Geocoding API reports failure in
 * a 200 body. Unchecked, `ZERO_RESULTS` becomes an empty address that reads as
 * success and is stored as a real place.
 */
it('treats a ZERO_RESULTS body as not-found despite the HTTP 200', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(['status' => 'ZERO_RESULTS', 'results' => []], 200)]);

    expect(fn () => googleProvider($http)->geocode(new GeocodeQuery('nowhere at all', 'NG')))
        ->toThrow(GeoAddressNotFound::class);
});

it('treats an over-quota body as a quota failure, not an outage', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(['status' => 'OVER_QUERY_LIMIT'], 200)]);

    expect(fn () => googleProvider($http)->geocode(new GeocodeQuery('12 Allen Avenue', 'NG')))
        ->toThrow(GeoQuotaExceeded::class);
});

it('rejects a result carrying no usable point', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response([
        'status' => 'OK',
        'results' => [['formatted_address' => 'Somewhere', 'geometry' => ['location' => []]]],
    ])]);

    expect(fn () => googleProvider($http)->geocode(new GeocodeQuery('12 Allen Avenue', 'NG')))
        ->toThrow(GeoAddressNotFound::class);
});

it('reverse-geocodes a point and reports a coordinate-shaped not-found', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(geocodeFixture())]);

    expect(googleProvider($http)->reverseGeocode(new Coordinates(6.6018, 3.3515))->address->locality)->toBe('Ikeja');

    $empty = new HttpFactory();
    $empty->fake(['maps.googleapis.com/*' => $empty->response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    expect(fn () => googleProvider($empty)->reverseGeocode(new Coordinates(0.5, 0.5)))
        ->toThrow(GeoAddressNotFound::class, 'No address could be found at those coordinates.');
});

it('constrains a geocode to the requested country', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(geocodeFixture())]);

    googleProvider($http)->geocode(new GeocodeQuery('Ikeja', 'NG'));

    $http->assertSent(function (Request $request): bool {
        expect($request->url())->toContain('components=country%3ANG');

        return true;
    });
});

// ----------------------------------------------------------------- routing

it('reads distance and duration out of a Routes response', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        'routes' => [[
            'distanceMeters' => 26_412,
            'duration' => '5400s',
            'staticDuration' => '3900s',
            'polyline' => ['encodedPolyline' => 'abc123'],
        ]],
    ])]);

    $route = googleProvider($http)->route(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
        TravelMode::TwoWheeler,
        trafficAware: true,
    ));

    expect($route->distanceMetres)->toBe(26_412)
        // Protobuf durations arrive as "3900s", not as a number.
        ->and($route->durationSeconds)->toBe(3_900)
        ->and($route->durationInTrafficSeconds)->toBe(5_400)
        ->and($route->source)->toBe(RouteSource::Provider)
        ->and($route->provider)->toBe('google')
        ->and($route->polyline)->toBe('abc123')
        ->and($route->isBillable(new DateTimeImmutable(), 3_600))->toBeTrue();
});

it('sends the platform travel mode as Google spells it', function (TravelMode $mode, string $expected): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        'routes' => [['distanceMeters' => 1_000, 'staticDuration' => '300s']],
    ])]);

    googleProvider($http)->route(new RouteQuery(new Coordinates(6.6, 3.3), new Coordinates(6.4, 3.4), $mode));

    $http->assertSent(function (Request $request) use ($expected): bool {
        expect($request->data()['travelMode'] ?? null)->toBe($expected);

        return true;
    });
})->with([
    [TravelMode::Driving, 'DRIVE'],
    [TravelMode::TwoWheeler, 'TWO_WHEELER'],
    [TravelMode::Bicycle, 'BICYCLE'],
    [TravelMode::Walking, 'WALK'],
]);

/**
 * Traffic-aware routing costs more per call and means nothing for a walking
 * route, so asking for it on a mode that cannot use it is silently downgraded
 * rather than billed.
 */
it('does not request traffic awareness for a mode that cannot use it', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        'routes' => [['distanceMeters' => 1_000, 'staticDuration' => '900s']],
    ])]);

    $route = googleProvider($http)->route(new RouteQuery(
        new Coordinates(6.6, 3.3),
        new Coordinates(6.4, 3.4),
        TravelMode::Walking,
        trafficAware: true,
    ));

    expect($route->durationInTrafficSeconds)->toBeNull();

    $http->assertSent(function (Request $request): bool {
        expect($request->data()['routingPreference'] ?? null)->toBe('TRAFFIC_UNAWARE');

        return true;
    });
});

it('reports an unroutable pair rather than returning a zero distance', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response(['routes' => []])]);

    expect(fn () => googleProvider($http)->route(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(51.5074, -0.1278),
        TravelMode::TwoWheeler,
    )))->toThrow(GeoProviderUnavailable::class);
});

it('drops unroutable cells from a matrix instead of reporting them as zero', function (): void {
    $http = new HttpFactory();
    $http->fake(['routes.googleapis.com/*' => $http->response([
        ['originIndex' => 0, 'destinationIndex' => 0, 'distanceMeters' => 12_000, 'duration' => '1800s', 'condition' => 'ROUTE_EXISTS'],
        ['originIndex' => 1, 'destinationIndex' => 0, 'condition' => 'ROUTE_NOT_FOUND'],
    ])]);

    $matrix = googleProvider($http)->matrix(
        [new Coordinates(6.6, 3.3), new Coordinates(6.5, 3.2)],
        [new Coordinates(6.4, 3.4)],
        TravelMode::TwoWheeler,
    );

    expect($matrix->cell(0, 0))->toBe(['distanceMetres' => 12_000, 'durationSeconds' => 1_800])
        // Absent, so a caller cannot mistake "no road" for "zero metres away".
        ->and($matrix->cell(1, 0))->toBeNull();
});

// ------------------------------------------------------------------ places

it('translates place suggestions', function (): void {
    $http = new HttpFactory();
    $http->fake(['places.googleapis.com/*' => $http->response([
        'suggestions' => [
            [
                'placePrediction' => [
                    'placeId' => 'ChIJplace1',
                    'text' => ['text' => 'Allen Avenue, Ikeja, Lagos, Nigeria'],
                    'structuredFormat' => [
                        'mainText' => ['text' => 'Allen Avenue'],
                        'secondaryText' => ['text' => 'Ikeja, Lagos, Nigeria'],
                    ],
                ],
            ],
            // A query prediction rather than a place: it has no place id and
            // cannot be turned into an address, so it is dropped.
            ['queryPrediction' => ['text' => ['text' => 'restaurants near me']]],
        ],
    ])]);

    $suggestions = googleProvider($http)->autocomplete('Allen', countryCode: 'NG');

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->providerPlaceId)->toBe('ChIJplace1')
        ->and($suggestions[0]->mainText)->toBe('Allen Avenue');
});

it('does not spend a request on an empty autocomplete input', function (): void {
    $http = new HttpFactory();
    $http->fake();

    expect(googleProvider($http)->autocomplete('   '))->toBe([]);

    $http->assertNothingSent();
});

// -------------------------------------------------------- failure handling

it('translates transport and HTTP failures into platform exceptions', function (int $status, string $expected): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response(['error' => ['message' => 'API key not valid: AIzaSyTOPSECRET']], $status)]);

    expect(fn () => googleProvider($http)->geocode(new GeocodeQuery('12 Allen Avenue', 'NG')))->toThrow($expected);
})->with([
    [429, GeoQuotaExceeded::class],
    [403, GeoProviderUnavailable::class],
    [401, GeoProviderUnavailable::class],
    [500, GeoProviderUnavailable::class],
    [503, GeoProviderUnavailable::class],
]);

/**
 * Google's error bodies quote the request and can quote the credential. An
 * exception message travels into logs, error trackers and sometimes into an API
 * response, so none of it is passed through — and for a geocode the request
 * itself is somebody's home address.
 */
it('never leaks the credential or the queried address into an error message', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response([
        'error' => ['message' => 'API key not valid: '.GEO_TEST_KEY, 'status' => 'PERMISSION_DENIED'],
    ], 403)]);

    try {
        googleProvider($http)->geocode(new GeocodeQuery('14 Adeola Odeku Street, Victoria Island', 'NG'));
        expect()->fail('The provider should have refused.');
    } catch (GeoProviderUnavailable $e) {
        expect($e->getMessage())->not->toContain(GEO_TEST_KEY)
            ->and($e->getMessage())->not->toContain('Adeola Odeku')
            ->and($e->errorCode())->toBe('GEO_PROVIDER_UNAVAILABLE');
    }
});

it('reports an unreadable response as a provider failure rather than an empty result', function (): void {
    $http = new HttpFactory();
    $http->fake(['maps.googleapis.com/*' => $http->response('<html>502 Bad Gateway</html>', 200)]);

    expect(fn () => googleProvider($http)->geocode(new GeocodeQuery('12 Allen Avenue', 'NG')))
        ->toThrow(GeoProviderUnavailable::class);
});
