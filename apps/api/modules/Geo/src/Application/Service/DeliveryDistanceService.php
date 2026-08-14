<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Contracts\DeliveryDistance;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Route\Eta;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;

/**
 * The published implementation of "how far is this delivery, really?".
 *
 * Sits between the routing machinery and the contexts that price deliveries.
 * Everything crossing this boundary carries its provenance, because the single
 * most expensive mistake available here is a distance whose origin nobody
 * checked.
 *
 * ## The sanity ceiling
 *
 * A routed distance more than `max_detour_ratio` times the straight line is
 * rejected. That is not fine-tuning: a provider that returns a ferry route, a
 * wrong hemisphere or a mis-parsed field produces a number that is plausible in
 * type and absurd in value, and a bad number on a customer's bill is worse than
 * no number. Haversine is used *here*, as a sanity check on a routed result —
 * which is a legitimate use of it, and the opposite of billing against it.
 */
final readonly class DeliveryDistanceService implements DeliveryDistanceProvider
{
    public function __construct(
        private RoutingService $routing,
        private int $staleGraceSeconds,
        private bool $routedPricingEnabled,
        private float $maxDetourRatio,
        private string $defaultTravelMode = 'two_wheeler',
    ) {
    }

    public function between(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
        ?string $travelMode = null,
    ): ?DeliveryDistance {
        $origin = Coordinates::tryFromMixed($originLatitude, $originLongitude);
        $destination = Coordinates::tryFromMixed($destinationLatitude, $destinationLongitude);

        if ($origin === null || $destination === null) {
            return null;
        }

        $route = $this->route($origin, $destination, $travelMode);

        return $route === null ? null : $this->toContract($route);
    }

    public function routedPricingEnabled(): bool
    {
        return $this->routedPricingEnabled;
    }

    /**
     * The best available route between two points, or null.
     *
     * Null is a real answer here — it means "no measured journey exists", and
     * the caller is expected to fall through to a merchant flat fee or refuse.
     * Returning a fabricated distance instead would remove the caller's ability
     * to make that choice.
     */
    public function route(Coordinates $origin, Coordinates $destination, ?string $travelMode = null): ?Route
    {
        $mode = TravelMode::tryFrom($travelMode ?? $this->defaultTravelMode) ?? TravelMode::TwoWheeler;

        $route = $this->routing->attempt(new RouteQuery(
            origin: $origin,
            destination: $destination,
            travelMode: $mode,
            trafficAware: $mode->isTrafficSensitive(),
        ));

        if ($route === null) {
            return null;
        }

        return $this->isPlausible($route, $origin, $destination) ? $route : null;
    }

    /**
     * An arrival estimate for a journey.
     *
     * Separate from the distance because the two degrade differently: a
     * distance from this morning is still a fine basis for a fee, while a
     * duration from this morning is a poor basis for "your food arrives at".
     * The ETA therefore reports its own confidence rather than pretending
     * a stale duration is a fresh one.
     */
    public function eta(Coordinates $origin, Coordinates $destination, ?string $travelMode = null): ?Eta
    {
        $route = $this->route($origin, $destination, $travelMode);

        return $route === null ? null : Eta::fromRoute($route);
    }

    /**
     * Reject a routed result that cannot be describing this journey.
     *
     * The straight line is a hard lower bound on any real route, so a ratio far
     * above 1 means something went wrong upstream rather than that the roads
     * are bad. Very short journeys are exempt: over a hundred metres the ratio
     * is dominated by the block a one-way system forces you around, and a
     * legitimate 5× is common.
     */
    private function isPlausible(Route $route, Coordinates $origin, Coordinates $destination): bool
    {
        $straightLine = Haversine::metres($origin, $destination);

        if ($straightLine < 250.0) {
            return true;
        }

        return $route->distanceMetres <= $straightLine * $this->maxDetourRatio;
    }

    private function toContract(Route $route): DeliveryDistance
    {
        $now = new DateTimeImmutable();

        return new DeliveryDistance(
            distanceMetres: $route->distanceMetres,
            durationSeconds: $route->durationSeconds,
            source: $route->source->value,
            isBillable: $route->isBillable($now, $this->staleGraceSeconds),
            ageSeconds: $route->ageSeconds($now),
            durationInTrafficSeconds: $route->durationInTrafficSeconds,
        );
    }
}
