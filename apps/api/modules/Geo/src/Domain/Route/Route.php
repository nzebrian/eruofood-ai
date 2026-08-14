<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Route;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * A journey between two points, as measured by whoever measured it.
 *
 * `source` is the field that matters most. Delivery pricing consults it before
 * billing anything: a provider result is authoritative, a cached one is
 * authoritative while fresh, and a haversine estimate never is at any age.
 * Without it a fallback is indistinguishable from a real answer — which is
 * precisely how a straight-line guess ends up on a customer's bill.
 *
 * Distances are integer metres and durations integer seconds. Providers report
 * whole units, floats invite drift through arithmetic, and nobody needs
 * sub-metre precision on a delivery.
 */
final readonly class Route
{
    public function __construct(
        public Coordinates $origin,
        public Coordinates $destination,
        public int $distanceMetres,
        public int $durationSeconds,
        public TravelMode $travelMode,
        public RouteSource $source,
        public string $provider,
        public DateTimeImmutable $calculatedAt,
        /** Traffic-adjusted duration, when the provider supplied one. */
        public ?int $durationInTrafficSeconds = null,
        public ?string $providerRouteId = null,
        /** Encoded geometry, kept only when a caller will draw it. */
        public ?string $polyline = null,
    ) {
    }

    /**
     * Whether this distance may be charged for.
     *
     * Two conditions, both necessary: the source must be billable at all, and a
     * cached result must still be within its grace period. A route from this
     * morning is a defensible basis for a fee; one from last week is a guess
     * wearing a provider's name.
     */
    public function isBillable(DateTimeImmutable $now, int $staleGraceSeconds): bool
    {
        if (! $this->source->isBillable()) {
            return false;
        }

        if ($this->source === RouteSource::Cache) {
            return $this->ageSeconds($now) <= $staleGraceSeconds;
        }

        return true;
    }

    public function ageSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->calculatedAt->getTimestamp());
    }

    public function distanceKm(): float
    {
        return $this->distanceMetres / 1000.0;
    }

    /** The duration to show a customer: traffic-aware when we have it. */
    public function effectiveDurationSeconds(): int
    {
        return $this->durationInTrafficSeconds ?? $this->durationSeconds;
    }

    public function estimatedArrival(DateTimeImmutable $departingAt): DateTimeImmutable
    {
        return $departingAt->modify(sprintf('+%d seconds', $this->effectiveDurationSeconds()));
    }

    /** Re-badge a provider result as having come from cache, preserving its original timing. */
    public function asCached(): self
    {
        return new self(
            $this->origin,
            $this->destination,
            $this->distanceMetres,
            $this->durationSeconds,
            $this->travelMode,
            RouteSource::Cache,
            $this->provider,
            $this->calculatedAt,
            $this->durationInTrafficSeconds,
            $this->providerRouteId,
            $this->polyline,
        );
    }
}
