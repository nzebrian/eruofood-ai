<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\Exception\GeoInvalidCoordinates;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * The coordinate type's invariants.
 *
 * These matter more than they look. A coordinate that silently accepts garbage
 * produces a delivery routed to the Gulf of Guinea rather than an error anybody
 * would notice.
 */
it('accepts a real Lagos point', function (): void {
    $point = new Coordinates(6.4550, 3.3841);

    expect($point->latitude)->toBe(6.4550)
        ->and($point->longitude)->toBe(3.3841);
});

it('rejects a latitude outside the possible range', function (): void {
    new Coordinates(95.0, 3.3841);
})->throws(GeoInvalidCoordinates::class);

it('rejects a longitude outside the possible range', function (): void {
    new Coordinates(6.4550, 200.0);
})->throws(GeoInvalidCoordinates::class);

it('rejects NAN and INF rather than storing them', function (): void {
    expect(fn (): Coordinates => new Coordinates(NAN, 3.0))->toThrow(GeoInvalidCoordinates::class)
        ->and(fn (): Coordinates => new Coordinates(6.0, INF))->toThrow(GeoInvalidCoordinates::class);
});

/**
 * The specific bug this prevents: PHP casts "abc" to 0.0 without complaint, and
 * (0, 0) is a real place in the Gulf of Guinea roughly 600 km south of Lagos.
 * A coerced coordinate is not an obviously-wrong coordinate — it is a plausible
 * one in the wrong ocean.
 */
it('refuses to coerce non-numeric input into the null island', function (): void {
    expect(fn (): Coordinates => Coordinates::fromMixed('abc', 'def'))
        ->toThrow(GeoInvalidCoordinates::class)
        ->and(Coordinates::tryFromMixed('abc', 'def'))->toBeNull()
        ->and(Coordinates::tryFromMixed(null, null))->toBeNull();
});

it('accepts numeric strings, which is what a database row actually holds', function (): void {
    $point = Coordinates::fromMixed('6.4550000', '3.3841000');

    expect($point->latitude)->toBe(6.455);
});

it('rounds for cache keys and for public display', function (): void {
    $point = new Coordinates(6.4550123, 3.3841987);

    expect($point->roundedTo(4)->latitude)->toBe(6.455)
        ->and($point->roundedTo(3)->longitude)->toBe(3.384);
});

/**
 * Two writings of the same place must produce one cache entry, not two. Without
 * fixed decimals PHP renders 6.5 as "6.5" and 6.50 as "6.5" but 6.4550000 as
 * "6.455", so the key would depend on how the float happened to be written.
 */
it('produces a stable cache key regardless of how the float was written', function (): void {
    expect((new Coordinates(6.5, 3.3))->toKey())
        ->toBe((new Coordinates(6.50000, 3.30000))->toKey())
        ->and((new Coordinates(6.4550, 3.3841))->toKey())->toBe('6.45500,3.38410');
});

it('treats equality at storage precision, not float identity', function (): void {
    $a = new Coordinates(6.4550000, 3.3841000);
    $b = new Coordinates(6.45500001, 3.38410001);

    expect($a->equals($b))->toBeTrue();
});
