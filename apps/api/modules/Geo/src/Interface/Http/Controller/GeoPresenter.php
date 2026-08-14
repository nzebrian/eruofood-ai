<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Address\CustomerAddress;
use EruoFood\Geo\Domain\Location\Location;
use EruoFood\Geo\Domain\Rider\RiderLocation;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZone;

/**
 * The one place that decides how much of a location leaves the building.
 *
 * There are three audiences and they get three different amounts of detail:
 *
 * - **The owner** sees everything they entered, including their delivery
 *   instructions and full coordinates.
 * - **The public** sees a merchant's area and a coarsened point — enough to
 *   draw a pin on a map, not enough to identify a doorway.
 * - **The back office** sees what it needs to resolve an operational problem
 *   and no more.
 *
 * Centralised deliberately. Per-controller shaping is how a private field ends
 * up on a public endpoint one refactor later, and coordinates are the field
 * where that mistake is least recoverable — an address, once published, cannot
 * be unpublished.
 */
final readonly class GeoPresenter
{
    public function __construct(private int $publicCoordinatePrecision = 3)
    {
    }

    /**
     * A saved address, for the customer who owns it.
     *
     * @return array<string, mixed>
     */
    public function ownAddress(CustomerAddress $address, ?Location $location): array
    {
        return [
            'id' => $address->id(),
            'label' => $address->label()->value,
            'display_name' => $address->displayName(),
            'custom_name' => $address->customName(),
            // Theirs, so they see it in full — including the instructions,
            // which are personal and never appear anywhere else.
            'delivery_instructions' => $address->deliveryInstructions(),
            'contact_phone' => $address->contactPhone(),
            'is_default' => $address->isDefault(),
            'last_used_at' => $address->lastUsedAt()?->format(DATE_ATOM),
            'created_at' => $address->createdAt()->format(DATE_ATOM),
            'location' => $location === null ? null : [
                'id' => $location->id(),
                'formatted_address' => $location->address()->displayLine(),
                'area' => $location->address()->areaLine(),
                'country_code' => $location->countryCode(),
                'coordinates' => $this->exactCoordinates($location->coordinates()),
                'precision' => $location->precision()->value,
                'status' => $location->status()->value,
                // What a customer actually needs to know: whether a rider can
                // be sent here at all.
                'is_deliverable' => $location->isDeliverable(),
            ],
        ];
    }

    /**
     * A merchant's location, as the public sees it.
     *
     * Coarsened to about 110 metres. A customer needs to know a restaurant is
     * in Ikeja and roughly where; nobody browsing a menu needs its loading bay
     * to seven decimal places.
     *
     * @return array<string, mixed>
     */
    public function publicLocation(Location $location): array
    {
        return [
            'area' => $location->address()->areaLine(),
            'locality' => $location->address()->locality,
            'admin_area' => $location->address()->adminArea,
            'country_code' => $location->countryCode(),
            'coordinates' => $this->coarseCoordinates($location->coordinates()),
            'precision_metres' => $this->approximatePrecisionMetres(),
        ];
    }

    /**
     * A merchant's own location, for the merchant.
     *
     * Full precision: it is their address, they typed it, and they need to see
     * exactly where the platform will send riders.
     *
     * @return array<string, mixed>
     */
    public function merchantLocation(Location $location): array
    {
        return [
            'id' => $location->id(),
            'formatted_address' => $location->address()->displayLine(),
            'area' => $location->address()->areaLine(),
            'country_code' => $location->countryCode(),
            'coordinates' => $this->exactCoordinates($location->coordinates()),
            'precision' => $location->precision()->value,
            'status' => $location->status()->value,
            'is_deliverable' => $location->isDeliverable(),
            'geocoded_at' => $location->geocodedAt()?->format(DATE_ATOM),
            'verified_at' => $location->verifiedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * A route, with its provenance attached.
     *
     * `source` and `is_billable` travel with every quote because a client that
     * cannot tell a fresh measurement from a stale one will eventually present
     * one as the other.
     *
     * @return array<string, mixed>
     */
    public function route(Route $route, int $staleGraceSeconds): array
    {
        return [
            'distance_metres' => $route->distanceMetres,
            'duration_seconds' => $route->effectiveDurationSeconds(),
            'duration_in_traffic_seconds' => $route->durationInTrafficSeconds,
            'travel_mode' => $route->travelMode->value,
            'source' => $route->source->value,
            'calculated_at' => $route->calculatedAt->format(DATE_ATOM),
            'age_seconds' => $route->ageSeconds(new DateTimeImmutable()),
            'is_billable' => $route->isBillable(new DateTimeImmutable(), $staleGraceSeconds),
        ];
    }

    /**
     * A rider's position, for an operator or a tracking customer.
     *
     * Deliberately spare. The account behind the rider is not named, the
     * accuracy is reported so a poor fix cannot masquerade as a good one, and
     * the age is explicit — a position with no timestamp is exactly what made
     * the pre-M25 columns unusable.
     *
     * @return array<string, mixed>
     */
    public function riderLocation(RiderLocation $location, int $staleAfterSeconds): array
    {
        $now = new DateTimeImmutable();

        return [
            'rider_id' => $location->riderId(),
            'coordinates' => $this->exactCoordinates($location->coordinates()),
            'accuracy_metres' => $location->accuracyMetres(),
            'heading_degrees' => $location->headingDegrees(),
            'recorded_at' => $location->recordedAt()->format(DATE_ATOM),
            'age_seconds' => $location->ageSeconds($now),
            'is_stale' => $location->isStale($now, $staleAfterSeconds),
        ];
    }

    /** @return array<string, mixed> */
    public function zone(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->id(),
            'name' => $zone->name(),
            'owner_type' => $zone->ownerType(),
            'owner_id' => $zone->ownerId(),
            'zone_type' => $zone->zoneType()->value,
            'centre' => $this->exactCoordinates($zone->centre()),
            'radius_metres' => $zone->radiusMetres(),
            'polygon' => $zone->polygonPoints(),
            'country_code' => $zone->countryCode(),
            'admin_area' => $zone->adminArea(),
            'fee_minor' => $zone->feeMinor(),
            'min_order_minor' => $zone->minOrderMinor(),
            'is_restricted' => $zone->isRestricted(),
            'is_active' => $zone->isActive(),
            'priority' => $zone->priority(),
        ];
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function exactCoordinates(?Coordinates $coordinates): ?array
    {
        return $coordinates?->toArray();
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function coarseCoordinates(?Coordinates $coordinates): ?array
    {
        return $coordinates?->roundedTo($this->publicCoordinatePrecision)->toArray();
    }

    /**
     * How wide the published point is, in metres, so a client can draw an
     * honest circle rather than a falsely precise pin.
     */
    private function approximatePrecisionMetres(): int
    {
        // One degree of latitude is about 111 km; each decimal place divides
        // that by ten.
        return (int) round(111_000 / (10 ** $this->publicCoordinatePrecision));
    }
}
