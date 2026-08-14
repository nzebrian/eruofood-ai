<?php

declare(strict_types=1);

use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Geo\Infrastructure\Provider\Mock\MockMapProvider;

/**
 * The offline provider the whole suite runs against.
 *
 * Its value is entirely in being realistic: if it were a stub returning fixed
 * numbers, every test above it would pass while proving nothing about cache
 * keys, fallback ordering or error translation. These tests hold it to that —
 * particularly the failure paths, which are the part that usually goes
 * untested precisely because a stub cannot produce them.
 */
function mockProvider(): MockMapProvider
{
    return new MockMapProvider(['seed' => 'eruofood']);
}

it('geocodes the same address to the same point every time', function (): void {
    $query = new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG');

    $first = mockProvider()->geocode($query);
    $second = mockProvider()->geocode($query);

    expect($first->coordinates->toKey())->toBe($second->coordinates->toKey());
});

it('normalises whitespace and case so one place is one cache key', function (): void {
    $a = new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG');
    $b = new GeocodeQuery('  12   ALLEN Avenue,   Ikeja  ', 'ng');

    expect($a->normalised())->toBe($b->normalised())
        ->and(mockProvider()->geocode($a)->coordinates->toKey())
        ->toBe(mockProvider()->geocode($b)->coordinates->toKey());
});

it('geocodes different addresses to different points', function (): void {
    $a = mockProvider()->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));
    $b = mockProvider()->geocode(new GeocodeQuery('4 Adeola Odeku, Victoria Island', 'NG'));

    expect($a->coordinates->toKey())->not->toBe($b->coordinates->toKey());
});

it('reports a not-found address rather than inventing a point', function (): void {
    mockProvider()->geocode(new GeocodeQuery('nowhere at all', 'NG'));
})->throws(GeoAddressNotFound::class);

it('reports provider unavailability on demand, so the fallback chain is reachable', function (): void {
    mockProvider()->geocode(new GeocodeQuery('outage street', 'NG'));
})->throws(GeoProviderUnavailable::class);

it('returns coarse precision when the match is coarse', function (): void {
    expect(mockProvider()->geocode(new GeocodeQuery('approximate area', 'NG'))->precision)
        ->toBe(LocationPrecision::Approximate)
        ->and(mockProvider()->geocode(new GeocodeQuery('12 Allen Avenue', 'NG'))->precision)
        ->toBe(LocationPrecision::Rooftop);
});

it('reverse-geocodes a point to an address and refuses the ocean square', function (): void {
    $result = mockProvider()->reverseGeocode(new Coordinates(6.4550, 3.3841));

    expect($result->address->countryCode)->toBe('NG')
        ->and($result->address->displayLine())->toContain('Lagos');

    expect(fn () => mockProvider()->reverseGeocode(new Coordinates(0.5, 0.5)))
        ->toThrow(GeoAddressNotFound::class);
});

/**
 * The road factor is deliberately not 1.0. A mock that returned the straight
 * line would let a bug that bypasses routing entirely pass every test in the
 * suite, silently reintroducing the under-charge M25 exists to fix.
 */
it('returns a routed distance meaningfully longer than the straight line', function (): void {
    $origin = new Coordinates(6.6018, 3.3515);
    $destination = new Coordinates(6.4281, 3.4219);

    $route = mockProvider()->route(new RouteQuery($origin, $destination, TravelMode::TwoWheeler));
    $straightLine = Haversine::metres($origin, $destination);

    expect($route->distanceMetres)->toBeGreaterThan((int) ($straightLine * 1.2))
        ->and($route->source)->toBe(RouteSource::Provider)
        ->and($route->travelMode)->toBe(TravelMode::TwoWheeler)
        ->and($route->durationSeconds)->toBeGreaterThan(0);
});

it('returns a longer duration when asked for a traffic-aware route', function (): void {
    $origin = new Coordinates(6.6018, 3.3515);
    $destination = new Coordinates(6.4281, 3.4219);

    $plain = mockProvider()->route(new RouteQuery($origin, $destination, TravelMode::TwoWheeler));
    $traffic = mockProvider()->route(new RouteQuery($origin, $destination, TravelMode::TwoWheeler, trafficAware: true));

    expect($plain->durationInTrafficSeconds)->toBeNull()
        ->and($traffic->durationInTrafficSeconds)->toBeGreaterThan($traffic->durationSeconds)
        ->and($traffic->effectiveDurationSeconds())->toBeGreaterThan($plain->effectiveDurationSeconds());
});

it('fails routing to an unreachable destination', function (): void {
    mockProvider()->route(new RouteQuery(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(0.5, 0.5),
        TravelMode::TwoWheeler,
    ));
})->throws(GeoProviderUnavailable::class);

it('builds a full distance matrix', function (): void {
    $origins = [new Coordinates(6.6018, 3.3515), new Coordinates(6.5000, 3.3600)];
    $destinations = [new Coordinates(6.4281, 3.4219)];

    $matrix = mockProvider()->matrix($origins, $destinations, TravelMode::TwoWheeler);

    expect($matrix->cell(0, 0))->toHaveKeys(['distanceMetres', 'durationSeconds'])
        ->and($matrix->cell(1, 0)['distanceMetres'])->toBeLessThan($matrix->cell(0, 0)['distanceMetres'])
        ->and($matrix->cell(0, 5))->toBeNull();
});

it('suggests places and returns nothing for an empty or hopeless input', function (): void {
    expect(mockProvider()->autocomplete('Allen'))->toHaveCount(3)
        ->and(mockProvider()->autocomplete('   '))->toBe([])
        ->and(mockProvider()->autocomplete('nowhere'))->toBe([]);
});
