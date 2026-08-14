<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/**
 * How exactly a geocode landed.
 *
 * The distinction that matters operationally: `Rooftop` is a building,
 * `Approximate` may be the centre of a city. Both are "geocoded" and only one
 * is safe to route a rider to or bill a journey against.
 */
enum LocationPrecision: string
{
    case Rooftop = 'rooftop';
    case RangeInterpolated = 'range_interpolated';
    case GeometricCentre = 'geometric_centre';
    case Approximate = 'approximate';
    case Unknown = 'unknown';

    /** Whether this is precise enough to dispatch a rider to. */
    public function isDeliverable(): bool
    {
        return in_array($this, [self::Rooftop, self::RangeInterpolated], true);
    }

    /** A coarse 0–1 confidence, for ranking and for showing a reviewer why something was flagged. */
    public function confidence(): float
    {
        return match ($this) {
            self::Rooftop => 1.0,
            self::RangeInterpolated => 0.8,
            self::GeometricCentre => 0.5,
            self::Approximate => 0.3,
            self::Unknown => 0.0,
        };
    }
}
