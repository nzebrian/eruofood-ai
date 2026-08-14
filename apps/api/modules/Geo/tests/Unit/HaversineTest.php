<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Search\Domain\ValueObject\GeoPoint;

/**
 * The platform's one distance implementation.
 *
 * Before M25 there were two, disagreeing on the Earth's radius, so the same
 * journey measured differently depending on which module asked. The last test
 * here is the one that keeps them from drifting apart again.
 */
it('measures a known distance correctly', function (): void {
    // Ikeja to Victoria Island, about 14 km straight-line.
    $ikeja = new Coordinates(6.6018, 3.3515);
    $vi = new Coordinates(6.4281, 3.4219);

    expect(Haversine::metres($ikeja, $vi))->toBeGreaterThan(19_000.0)
        ->toBeLessThan(21_000.0);
});

it('measures zero distance between a point and itself', function (): void {
    $point = new Coordinates(6.4550, 3.3841);

    expect(Haversine::metres($point, $point))->toBe(0.0);
});

it('is symmetric', function (): void {
    $a = new Coordinates(6.6018, 3.3515);
    $b = new Coordinates(6.4281, 3.4219);

    expect(Haversine::metres($a, $b))->toBe(Haversine::metres($b, $a));
});

/**
 * The box has to be generous or the prefilter would discard real matches before
 * the exact pass ever measured them — a proximity search that silently misses
 * the nearest result.
 */
it('produces a bounding box that fully contains its circle', function (): void {
    $centre = new Coordinates(6.4550, 3.3841);
    $box = Haversine::boundingBox($centre, 5_000.0);

    // A point due north at exactly the radius must fall inside the box.
    $north = new Coordinates($centre->latitude + rad2deg(5_000.0 / Haversine::EARTH_RADIUS_METRES), $centre->longitude);

    expect($north->latitude)->toBeLessThanOrEqual($box['maxLat'])
        ->and($box['minLat'])->toBeLessThan($centre->latitude)
        ->and($box['maxLon'])->toBeGreaterThan($centre->longitude);
});

it('widens the box near a pole rather than dividing by zero', function (): void {
    $box = Haversine::boundingBox(new Coordinates(89.999, 0.0), 10_000.0);

    expect($box['maxLat'])->toBeLessThanOrEqual(90.0)
        ->and($box['minLon'])->toBeGreaterThanOrEqual(-180.0)
        ->and(is_finite($box['maxLon']))->toBeTrue();
});

it('answers containment consistently with the measured distance', function (): void {
    $centre = new Coordinates(6.4550, 3.3841);
    $near = new Coordinates(6.4560, 3.3850);

    expect(Haversine::isWithin($centre, $near, 1_000.0))->toBeTrue()
        ->and(Haversine::isWithin($centre, $near, 10.0))->toBeFalse();
});

/**
 * The regression that closes M25's first finding: Marketplace used an Earth
 * radius of 6371.0 and Search used 6371.0088, so a vendor 10 km away was 1.4 m
 * closer or further depending on which module was asked. Immaterial as a
 * distance; a real inconsistency as a fact.
 */
it('gives Marketplace and Search the same answer for the same journey', function (): void {
    $from = [6.6018, 3.3515];
    $to = [6.4281, 3.4219];

    $marketplace = (new GeoLocation(...$from))->distanceKmTo(new GeoLocation(...$to));
    $search = (new GeoPoint(...$from))->distanceKmTo(new GeoPoint(...$to));
    $canonical = Haversine::kilometres(new Coordinates(...$from), new Coordinates(...$to));

    expect($marketplace)->toBe($search)->toBe($canonical);
});
