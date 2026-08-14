<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * The rule that protects what a customer is charged.
 *
 * A route carries where its numbers came from, and pricing consults that before
 * billing anything. Without it a fallback is indistinguishable from a real
 * answer — which is precisely how a straight-line guess ends up on a bill,
 * wrong in one direction, on every single order.
 */
function routeWith(RouteSource $source, DateTimeImmutable $calculatedAt): Route
{
    return new Route(
        origin: new Coordinates(6.6018, 3.3515),
        destination: new Coordinates(6.4281, 3.4219),
        distanceMetres: 26_000,
        durationSeconds: 3_900,
        travelMode: TravelMode::TwoWheeler,
        source: $source,
        provider: 'mock',
        calculatedAt: $calculatedAt,
    );
}

it('bills a fresh provider result', function (): void {
    $now = new DateTimeImmutable('2026-08-13 12:00:00');

    expect(routeWith(RouteSource::Provider, $now)->isBillable($now, 21_600))->toBeTrue();
});

it('bills a cached result that is still inside the grace period', function (): void {
    $now = new DateTimeImmutable('2026-08-13 12:00:00');
    $route = routeWith(RouteSource::Cache, new DateTimeImmutable('2026-08-13 08:00:00'));

    expect($route->isBillable($now, 21_600))->toBeTrue();
});

it('refuses to bill a cached result past the grace period', function (): void {
    $now = new DateTimeImmutable('2026-08-13 12:00:00');
    $route = routeWith(RouteSource::Cache, new DateTimeImmutable('2026-08-12 12:00:00'));

    expect($route->isBillable($now, 21_600))->toBeFalse();
});

/**
 * The single most important assertion in the routing domain. A haversine
 * distance is legitimate for ranking and prefiltering and is never a price, at
 * any age, however fresh.
 */
it('never bills a haversine estimate, however fresh', function (): void {
    $now = new DateTimeImmutable('2026-08-13 12:00:00');

    expect(routeWith(RouteSource::Haversine, $now)->isBillable($now, 21_600))->toBeFalse()
        ->and(RouteSource::Haversine->isBillable())->toBeFalse()
        ->and(routeWith(RouteSource::Haversine, $now)->isBillable($now, PHP_INT_MAX))->toBeFalse();
});

it('re-badges a provider result as cached without moving its clock', function (): void {
    $calculatedAt = new DateTimeImmutable('2026-08-13 08:00:00');
    $cached = routeWith(RouteSource::Provider, $calculatedAt)->asCached();

    expect($cached->source)->toBe(RouteSource::Cache)
        // The age that decides billability must survive the re-badge, or a
        // week-old route would look like one measured this second.
        ->and($cached->calculatedAt)->toEqual($calculatedAt)
        ->and($cached->distanceMetres)->toBe(26_000);
});

it('prefers the traffic-aware duration when the provider supplied one', function (): void {
    $route = new Route(
        origin: new Coordinates(6.6018, 3.3515),
        destination: new Coordinates(6.4281, 3.4219),
        distanceMetres: 26_000,
        durationSeconds: 3_900,
        travelMode: TravelMode::TwoWheeler,
        source: RouteSource::Provider,
        provider: 'mock',
        calculatedAt: new DateTimeImmutable('2026-08-13 12:00:00'),
        durationInTrafficSeconds: 5_400,
    );

    expect($route->effectiveDurationSeconds())->toBe(5_400)
        ->and($route->estimatedArrival(new DateTimeImmutable('2026-08-13 12:00:00')))
        ->toEqual(new DateTimeImmutable('2026-08-13 13:30:00'));
});
