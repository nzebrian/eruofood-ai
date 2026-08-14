<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZone;

function zoneNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-13 10:00:00');
}

it('contains a point inside its radius and excludes one outside', function (): void {
    $centre = new Coordinates(6.4550, 3.3841);
    $zone = DeliveryZone::radius('z1', 'vendor', 'v1', 'Ikeja 5km', $centre, 5_000, zoneNow());

    expect($zone->contains(new Coordinates(6.4600, 3.3900)))->toBeTrue()
        // Victoria Island, well beyond 5 km.
        ->and($zone->contains(new Coordinates(6.4281, 3.4219)))->toBeFalse();
});

it('refuses a radius of zero, which would match nothing while looking configured', function (): void {
    DeliveryZone::radius('z1', 'vendor', 'v1', 'Broken', new Coordinates(6.45, 3.38), 0, zoneNow());
})->throws(GeoInvalidState::class);

it('refuses a polygon with fewer than three points', function (): void {
    DeliveryZone::polygon('z1', 'vendor', 'v1', 'Line', [[3.38, 6.45], [3.39, 6.46]], zoneNow());
})->throws(GeoInvalidState::class);

/**
 * Points are [lon, lat] in GeoJSON order — the reverse of how coordinates are
 * spoken — so a transposition bug would produce a zone somewhere in Somalia
 * that quietly contains nothing. This box is asymmetric on purpose: a swapped
 * pair fails it.
 */
it('contains a point inside a polygon and excludes one outside', function (): void {
    $zone = DeliveryZone::polygon('z1', 'vendor', 'v1', 'Ikeja box', [
        [3.30, 6.50],
        [3.40, 6.50],
        [3.40, 6.60],
        [3.30, 6.60],
    ], zoneNow());

    expect($zone->contains(new Coordinates(6.55, 3.35)))->toBeTrue()
        ->and($zone->contains(new Coordinates(6.45, 3.35)))->toBeFalse()
        ->and($zone->contains(new Coordinates(6.55, 3.45)))->toBeFalse();
});

/**
 * Real service areas are rarely convex — a lagoon or an estate boundary cuts
 * into them. Ray casting handles that; a naive bounding-box test does not, and
 * would promise delivery across the water.
 */
it('handles a concave polygon correctly', function (): void {
    // A "C" shape opening east: the notch is genuinely outside the zone.
    $zone = DeliveryZone::polygon('z1', 'vendor', 'v1', 'C shape', [
        [3.30, 6.50],
        [3.40, 6.50],
        [3.40, 6.52],
        [3.32, 6.52],
        [3.32, 6.58],
        [3.40, 6.58],
        [3.40, 6.60],
        [3.30, 6.60],
    ], zoneNow());

    expect($zone->contains(new Coordinates(6.51, 3.35)))->toBeTrue()
        // Inside the bounding box, inside the notch, outside the zone.
        ->and($zone->contains(new Coordinates(6.55, 3.36)))->toBeFalse();
});

/**
 * Ray casting walks the ring by consecutive integer index. A gapped array —
 * what you get from a request body after a point was removed, or from jsonb
 * that was filtered — would read past the end and produce a zone that contains
 * nothing while looking perfectly configured.
 */
it('normalises a gapped ring so containment still works', function (): void {
    $gapped = [0 => [3.30, 6.50], 2 => [3.40, 6.50], 5 => [3.40, 6.60], 9 => [3.30, 6.60]];

    $zone = DeliveryZone::polygon('z1', 'vendor', 'v1', 'Gapped box', $gapped, zoneNow());

    expect($zone->polygonPoints())->toBe([[3.30, 6.50], [3.40, 6.50], [3.40, 6.60], [3.30, 6.60]])
        ->and($zone->contains(new Coordinates(6.55, 3.35)))->toBeTrue()
        ->and($zone->contains(new Coordinates(6.45, 3.35)))->toBeFalse();
});

it('reports a bounding box that encloses its own shape', function (): void {
    $polygon = DeliveryZone::polygon('z1', 'vendor', 'v1', 'Box', [
        [3.30, 6.50], [3.40, 6.50], [3.40, 6.60], [3.30, 6.60],
    ], zoneNow());

    expect($polygon->boundingBox())->toBe([
        'minLat' => 6.50, 'maxLat' => 6.60, 'minLon' => 3.30, 'maxLon' => 3.40,
    ]);

    $radius = DeliveryZone::radius('z2', 'vendor', 'v1', 'Circle', new Coordinates(6.4550, 3.3841), 5_000, zoneNow());
    $box = $radius->boundingBox();

    expect($box)->not->toBeNull()
        ->and($box['minLat'])->toBeLessThan(6.4550)
        ->and($box['maxLat'])->toBeGreaterThan(6.4550);
});

/**
 * A deactivated zone must stop matching immediately. If it kept containing
 * points, switching it off would do nothing visible and a merchant would keep
 * receiving orders they had explicitly stopped accepting.
 */
it('contains nothing once deactivated', function (): void {
    $zone = DeliveryZone::radius('z1', 'vendor', 'v1', 'Ikeja', new Coordinates(6.4550, 3.3841), 5_000, zoneNow());
    $inside = new Coordinates(6.4560, 3.3850);

    expect($zone->contains($inside))->toBeTrue();

    $zone->deactivate(zoneNow());

    expect($zone->contains($inside))->toBeFalse();
});
