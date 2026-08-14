<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\ZoneType;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\Exception\GeoNotAuthorized;
use EruoFood\Geo\Domain\Exception\GeoNotFound;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZone;
use EruoFood\Geo\Domain\Zone\DeliveryZoneRepository;

/**
 * Delivery zones: where a merchant or the platform will — and will not — serve.
 *
 * ## Ordering is the whole design
 *
 * Zones are consulted lowest-priority-number first, and the **first restricted
 * zone containing the point wins outright**. That ordering is what lets a
 * merchant carve an exclusion out of a broad service area — a gated estate, the
 * far side of a lagoon — and have it actually fire. Consult them in the other
 * order and the broad inclusion matches first, the exclusion never runs, and
 * the platform promises a delivery it cannot make.
 *
 * ## Why containment is arithmetic
 *
 * The bounding box narrows candidates with an indexed comparison; the exact
 * answer is ray casting in PHP. At the zone counts involved that is comfortably
 * fast, it behaves identically on both database engines, and it keeps PostGIS a
 * later option rather than a present dependency. If it is ever adopted, only
 * the repository changes.
 */
final readonly class DeliveryZoneService
{
    public function __construct(private DeliveryZoneRepository $zones)
    {
    }

    public function createRadiusZone(
        string $ownerType,
        ?string $ownerId,
        string $name,
        Coordinates $centre,
        int $radiusMetres,
        ?int $feeMinor = null,
        int $priority = 100,
    ): DeliveryZone {
        $zone = DeliveryZone::radius(
            $this->zones->nextIdentity(),
            $ownerType,
            $ownerId,
            $name,
            $centre,
            $radiusMetres,
            new DateTimeImmutable(),
            $feeMinor,
            $priority,
        );

        $this->zones->save($zone);

        return $zone;
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon [lon, lat] pairs, GeoJSON order
     */
    public function createPolygonZone(
        string $ownerType,
        ?string $ownerId,
        string $name,
        array $polygon,
        ?int $feeMinor = null,
        bool $isRestricted = false,
        int $priority = 100,
    ): DeliveryZone {
        $zone = DeliveryZone::polygon(
            $this->zones->nextIdentity(),
            $ownerType,
            $ownerId,
            $name,
            $polygon,
            new DateTimeImmutable(),
            $feeMinor,
            $isRestricted,
            $priority,
        );

        $this->zones->save($zone);

        return $zone;
    }

    /**
     * The zone that governs a point, if any.
     *
     * Returns the first match in priority order, restricted or not — the caller
     * needs to know *which* zone applied, not merely whether one did, because a
     * restricted match and an inclusive match mean opposite things.
     */
    public function zoneFor(Coordinates $point, ?string $ownerType = null, ?string $ownerId = null): ?DeliveryZone
    {
        foreach ($this->zones->candidatesFor($point, $ownerType, $ownerId) as $zone) {
            // The bounding box is a prefilter, not an answer: its corners lie
            // outside a circle and outside any non-rectangular polygon.
            if ($zone->contains($point)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Whether the platform will deliver to a point on a merchant's behalf.
     *
     * A restricted zone answers no even when a wider service area would have
     * said yes, because it was consulted first. A point in no zone at all also
     * answers no: a service area nobody drew is not a service area, and
     * defaulting to yes would have the platform quietly accepting deliveries to
     * anywhere on Earth.
     */
    public function isServiceable(Coordinates $point, ?string $ownerType = null, ?string $ownerId = null): bool
    {
        $zone = $this->zoneFor($point, $ownerType, $ownerId);

        return $zone !== null && ! $zone->isRestricted();
    }

    /** @return list<DeliveryZone> */
    public function forOwner(string $ownerType, ?string $ownerId, bool $activeOnly = true): array
    {
        return $this->zones->forOwner($ownerType, $ownerId, $activeOnly);
    }

    public function get(string $zoneId): DeliveryZone
    {
        $zone = $this->zones->findById($zoneId);

        if ($zone === null) {
            throw GeoNotFound::of('delivery zone', $zoneId);
        }

        return $zone;
    }

    /** A merchant may only touch their own zones; the platform's are not theirs. */
    public function getOwned(string $zoneId, string $ownerType, string $ownerId): DeliveryZone
    {
        $zone = $this->get($zoneId);

        if (! $zone->belongsTo($ownerType, $ownerId)) {
            throw new GeoNotAuthorized('That delivery zone does not belong to you.');
        }

        return $zone;
    }

    public function rename(string $zoneId, string $ownerType, string $ownerId, string $name): DeliveryZone
    {
        $zone = $this->getOwned($zoneId, $ownerType, $ownerId);
        $zone->rename($name, new DateTimeImmutable());
        $this->zones->save($zone);

        return $zone;
    }

    public function setActive(string $zoneId, string $ownerType, string $ownerId, bool $active): DeliveryZone
    {
        $zone = $this->getOwned($zoneId, $ownerType, $ownerId);
        $now = new DateTimeImmutable();

        $active ? $zone->activate($now) : $zone->deactivate($now);
        $this->zones->save($zone);

        return $zone;
    }

    /**
     * Validate a ring before it becomes a zone.
     *
     * Rejected here rather than at the database, so a merchant drawing a shape
     * gets a sentence they can act on instead of a constraint violation.
     *
     * @param array<array-key, mixed> $polygon
     * @return list<array{0: float, 1: float}>
     */
    public function parsePolygon(array $polygon): array
    {
        $points = [];

        foreach ($polygon as $pair) {
            if (! is_array($pair) || ! isset($pair[0], $pair[1]) || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
                throw new GeoInvalidState('Each polygon point must be a [longitude, latitude] pair.');
            }

            // Validated through Coordinates so an out-of-range point is caught
            // here — GeoJSON order is [lon, lat], which is the reverse of how
            // coordinates are spoken and a reliable source of transposed shapes.
            $point = Coordinates::tryFromMixed($pair[1], $pair[0]);

            if ($point === null) {
                throw new GeoInvalidState('A polygon point is not a valid coordinate. Points are [longitude, latitude].');
            }

            $points[] = [$point->longitude, $point->latitude];
        }

        if (count($points) < 3) {
            throw new GeoInvalidState('A polygon zone needs at least three points.');
        }

        return $points;
    }

    /**
     * Zone types a client may create, for a form or an API contract.
     *
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_map(static fn (ZoneType $t): string => $t->value, ZoneType::cases());
    }
}
