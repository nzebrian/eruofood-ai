<?php

declare(strict_types=1);

use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

it('computes a plausible great-circle distance', function (): void {
    // Victoria Island -> Lagos mainland, roughly 7-8 km.
    $d = (new GeoLocation(6.4550, 3.3841))->distanceKmTo(new GeoLocation(6.5244, 3.3792));
    expect($d)->toBeGreaterThan(6.0)->and($d)->toBeLessThan(9.0);
});

it('is zero to itself', function (): void {
    $p = new GeoLocation(6.5, 3.3);
    expect($p->distanceKmTo($p))->toBe(0.0);
});

it('rejects out-of-range coordinates', function (): void {
    expect(fn () => new GeoLocation(120, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new GeoLocation(0, 200))->toThrow(InvalidArgumentException::class);
});
