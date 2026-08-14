<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\ValueObject;

use EruoFood\Geo\Domain\Exception\GeoInvalidCoordinates;

/**
 * A WGS-84 point on the Earth's surface — the platform's single coordinate type.
 *
 * Before M25 there were two of these (Marketplace's `GeoLocation` and Search's
 * `GeoPoint`), each with its own haversine and, more awkwardly, its own Earth
 * radius. The same journey measured differently depending on which module
 * asked. Both survive as thin adapters over this type so their callers keep
 * working, but the arithmetic now happens in exactly one place.
 *
 * Rounding is a first-class operation here rather than something callers do ad
 * hoc, because two of M25's controls depend on it: cache keys hit only when
 * nearby requests round to the same value, and public listings must not publish
 * a customer's doorstep to seven decimal places.
 */
final readonly class Coordinates
{
    /**
     * Storage precision, matching `decimal(10,7)` in the schema — about 11 mm.
     * Far finer than any consumer needs, but it makes a stored value round-trip
     * exactly, which keeps equality checks honest.
     */
    public const STORAGE_PRECISION = 7;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw GeoInvalidCoordinates::latitude($latitude);
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw GeoInvalidCoordinates::longitude($longitude);
        }
    }

    /**
     * Build from values of unknown provenance — a request body, a provider
     * response, a database row.
     *
     * Non-numeric input is rejected rather than coerced: PHP would happily turn
     * "abc" into 0.0, and 0,0 is a real place in the Gulf of Guinea that a
     * Nigerian delivery could plausibly be routed towards.
     */
    public static function fromMixed(mixed $latitude, mixed $longitude): self
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw GeoInvalidCoordinates::notNumeric();
        }

        return new self((float) $latitude, (float) $longitude);
    }

    public static function tryFromMixed(mixed $latitude, mixed $longitude): ?self
    {
        try {
            return self::fromMixed($latitude, $longitude);
        } catch (GeoInvalidCoordinates) {
            return null;
        }
    }

    /**
     * Round to a given number of decimal places.
     *
     * Used for cache keys (coarser rounding means more hits) and for public
     * display (coarser rounding means less exposure). Roughly: 5 dp ≈ 1 m,
     * 4 dp ≈ 11 m, 3 dp ≈ 110 m, 2 dp ≈ 1.1 km.
     */
    public function roundedTo(int $decimals): self
    {
        return new self(
            round($this->latitude, $decimals),
            round($this->longitude, $decimals),
        );
    }

    /** Equality at storage precision — float identity is not a useful question here. */
    public function equals(self $other): bool
    {
        return $this->roundedTo(self::STORAGE_PRECISION)->latitude === $other->roundedTo(self::STORAGE_PRECISION)->latitude
            && $this->roundedTo(self::STORAGE_PRECISION)->longitude === $other->roundedTo(self::STORAGE_PRECISION)->longitude;
    }

    /**
     * A stable string form for cache keys and logs.
     *
     * Fixed decimals rather than PHP's default float formatting, so 6.5 and
     * 6.50 produce the same key instead of two cache entries for one place.
     */
    public function toKey(int $decimals = 5): string
    {
        return sprintf('%.'.$decimals.'F,%.'.$decimals.'F', $this->latitude, $this->longitude);
    }

    public function __toString(): string
    {
        return $this->toKey();
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return self::fromMixed($data['latitude'] ?? null, $data['longitude'] ?? null);
    }
}
