<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Coordinates that are not a place on Earth.
 *
 * Rejected at construction rather than stored and dealt with later: a bad
 * latitude that reaches the database becomes a routing request, a delivery
 * quote, and eventually a rider sent somewhere impossible.
 */
final class GeoInvalidCoordinates extends DomainException
{
    public static function latitude(float $value): self
    {
        return new self(sprintf('Latitude must be between -90 and 90 degrees; got %s.', self::describe($value)));
    }

    public static function longitude(float $value): self
    {
        return new self(sprintf('Longitude must be between -180 and 180 degrees; got %s.', self::describe($value)));
    }

    public static function notNumeric(): self
    {
        return new self('Coordinates must be numeric.');
    }

    public function errorCode(): string
    {
        return 'GEO_INVALID_COORDINATES';
    }

    /** NAN and INF have no useful string form; naming them beats printing garbage. */
    private static function describe(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => 'infinity',
            default => (string) $value,
        };
    }
}
