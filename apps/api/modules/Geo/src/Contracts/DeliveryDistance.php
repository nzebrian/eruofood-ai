<?php

declare(strict_types=1);

namespace EruoFood\Geo\Contracts;

/**
 * A measured journey, as other bounded contexts are allowed to see one.
 *
 * The published contract — Marketplace and Commerce depend on this and never on
 * Geo's domain objects, so the internals of routing stay changeable.
 *
 * `isBillable` is the field that matters. It is not advisory: a caller that
 * prices a delivery against a distance where this is false is charging for a
 * journey nobody measured.
 */
final readonly class DeliveryDistance
{
    public function __construct(
        public int $distanceMetres,
        public int $durationSeconds,
        /** `provider`, `cache`, or `haversine` — see {@see \EruoFood\Geo\Domain\Enum\RouteSource}. */
        public string $source,
        /** Whether this distance may be charged for. */
        public bool $isBillable,
        public int $ageSeconds,
        public ?int $durationInTrafficSeconds = null,
    ) {
    }

    public function distanceKm(): float
    {
        return $this->distanceMetres / 1000.0;
    }

    public function effectiveDurationSeconds(): int
    {
        return $this->durationInTrafficSeconds ?? $this->durationSeconds;
    }
}
