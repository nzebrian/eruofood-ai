<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Zone;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\ZoneType;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;

/**
 * An area a merchant or the platform will — or will not — deliver to.
 *
 * Three shapes, because a circle describes a real service area badly. A lagoon,
 * a gated estate or a bridge makes "within 5 km" and "actually reachable" very
 * different sets, and a merchant who has been burned by that wants to draw the
 * boundary themselves.
 *
 * `isRestricted` inverts the meaning: an exclusion inside a broader service
 * area. That is why zones carry a priority — the specific exclusion has to be
 * consulted before the general inclusion, or it never fires.
 *
 * Containment is arithmetic here rather than a spatial query. At the zone
 * counts involved that is comfortably fast, it works identically on both
 * database engines, and it keeps PostGIS a later option instead of a present
 * dependency.
 */
final class DeliveryZone
{
    /**
     * @param list<array{0: float, 1: float}>|null $polygon [lon, lat] pairs, GeoJSON order
     */
    private function __construct(
        private readonly string $id,
        private readonly string $ownerType,
        private readonly ?string $ownerId,
        private string $name,
        private readonly ZoneType $zoneType,
        private ?Coordinates $centre,
        private ?int $radiusMetres,
        private ?array $polygon,
        private ?string $countryCode,
        private ?string $adminArea,
        private ?int $feeMinor,
        private ?int $minOrderMinor,
        private bool $isRestricted,
        private bool $isActive,
        private int $priority,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function radius(
        string $id,
        string $ownerType,
        ?string $ownerId,
        string $name,
        Coordinates $centre,
        int $radiusMetres,
        DateTimeImmutable $now,
        ?int $feeMinor = null,
        int $priority = 100,
    ): self {
        if ($radiusMetres <= 0) {
            throw new GeoInvalidState('A delivery zone radius must be greater than zero.');
        }

        return new self(
            $id,
            $ownerType,
            $ownerId,
            $name,
            ZoneType::Radius,
            $centre,
            $radiusMetres,
            null,
            null,
            null,
            $feeMinor,
            null,
            false,
            true,
            $priority,
            $now,
            $now,
        );
    }

    /**
     * Any array of [lon, lat] pairs; the keys are discarded.
     *
     * Deliberately wider than `list<...>`. A ring arrives from jsonb, from a
     * request body, or from a merchant's map editor after a point was removed —
     * all of which can carry gaps in the keys. Ray casting walks the ring by
     * consecutive integer index, so a gapped array would read past the end and
     * produce a zone that contains nothing while looking perfectly configured.
     * Normalising here is the one place that cannot be forgotten.
     *
     * @param array<array-key, array{0: float, 1: float}> $polygon [lon, lat] pairs
     */
    public static function polygon(
        string $id,
        string $ownerType,
        ?string $ownerId,
        string $name,
        array $polygon,
        DateTimeImmutable $now,
        ?int $feeMinor = null,
        bool $isRestricted = false,
        int $priority = 100,
    ): self {
        // Three points is the minimum that encloses anything. Fewer is a line,
        // which would silently contain nothing.
        if (count($polygon) < 3) {
            throw new GeoInvalidState('A polygon zone needs at least three points.');
        }

        return new self(
            $id,
            $ownerType,
            $ownerId,
            $name,
            ZoneType::Polygon,
            null,
            null,
            array_values($polygon),
            null,
            null,
            $feeMinor,
            null,
            $isRestricted,
            true,
            $priority,
            $now,
            $now,
        );
    }

    /**
     * @param list<array{0: float, 1: float}>|null $polygon
     */
    public static function reconstitute(
        string $id,
        string $ownerType,
        ?string $ownerId,
        string $name,
        ZoneType $zoneType,
        ?Coordinates $centre,
        ?int $radiusMetres,
        ?array $polygon,
        ?string $countryCode,
        ?string $adminArea,
        ?int $feeMinor,
        ?int $minOrderMinor,
        bool $isRestricted,
        bool $isActive,
        int $priority,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $ownerType,
            $ownerId,
            $name,
            $zoneType,
            $centre,
            $radiusMetres,
            $polygon,
            $countryCode,
            $adminArea,
            $feeMinor,
            $minOrderMinor,
            $isRestricted,
            $isActive,
            $priority,
            $createdAt,
            $updatedAt,
        );
    }

    /** Whether a point falls inside this zone. */
    public function contains(Coordinates $point): bool
    {
        if (! $this->isActive) {
            return false;
        }

        return match ($this->zoneType) {
            ZoneType::Radius => $this->centre !== null
                && $this->radiusMetres !== null
                && Haversine::isWithin($this->centre, $point, (float) $this->radiusMetres),
            ZoneType::Polygon => $this->polygonContains($point),
            // Administrative zones are matched on the address's own fields, not
            // on coordinates — a point cannot tell you which LGA it is in.
            ZoneType::Administrative => false,
        };
    }

    /** Whether an address falls inside an administrative zone. */
    public function containsAdministratively(?string $countryCode, ?string $adminArea): bool
    {
        if (! $this->isActive || $this->zoneType !== ZoneType::Administrative) {
            return false;
        }

        if ($this->countryCode !== null && strcasecmp($this->countryCode, (string) $countryCode) !== 0) {
            return false;
        }

        return $this->adminArea === null || strcasecmp($this->adminArea, (string) $adminArea) === 0;
    }

    /**
     * Ray casting: count crossings of a ray from the point; odd means inside.
     *
     * Handles concave shapes and holes-by-winding correctly, which matters
     * because real service areas are rarely convex. Points are [lon, lat] in
     * GeoJSON order — the reverse of how coordinates are spoken aloud, and a
     * reliable source of bugs, hence the explicit naming below.
     *
     * @see https://en.wikipedia.org/wiki/Point_in_polygon
     */
    private function polygonContains(Coordinates $point): bool
    {
        if ($this->polygon === null || count($this->polygon) < 3) {
            return false;
        }

        $x = $point->longitude;
        $y = $point->latitude;
        $inside = false;
        $count = count($this->polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $this->polygon[$i][0];
            $yi = $this->polygon[$i][1];
            $xj = $this->polygon[$j][0];
            $yj = $this->polygon[$j][1];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * The zone's bounding box, cached in the database so an index can rule most
     * candidates out before any point-in-polygon arithmetic runs.
     *
     * @return array{minLat: float, maxLat: float, minLon: float, maxLon: float}|null
     */
    public function boundingBox(): ?array
    {
        if ($this->zoneType === ZoneType::Radius && $this->centre !== null && $this->radiusMetres !== null) {
            return Haversine::boundingBox($this->centre, (float) $this->radiusMetres);
        }

        if ($this->zoneType === ZoneType::Polygon && $this->polygon !== null && $this->polygon !== []) {
            $lons = array_column($this->polygon, 0);
            $lats = array_column($this->polygon, 1);

            return [
                'minLat' => min($lats),
                'maxLat' => max($lats),
                'minLon' => min($lons),
                'maxLon' => max($lons),
            ];
        }

        return null;
    }

    public function deactivate(DateTimeImmutable $now): void
    {
        $this->isActive = false;
        $this->updatedAt = $now;
    }

    public function activate(DateTimeImmutable $now): void
    {
        $this->isActive = true;
        $this->updatedAt = $now;
    }

    public function rename(string $name, DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function belongsTo(string $ownerType, string $ownerId): bool
    {
        return $this->ownerType === $ownerType && $this->ownerId === $ownerId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerType(): string
    {
        return $this->ownerType;
    }

    public function ownerId(): ?string
    {
        return $this->ownerId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function zoneType(): ZoneType
    {
        return $this->zoneType;
    }

    public function centre(): ?Coordinates
    {
        return $this->centre;
    }

    public function radiusMetres(): ?int
    {
        return $this->radiusMetres;
    }

    /** @return list<array{0: float, 1: float}>|null */
    public function polygonPoints(): ?array
    {
        return $this->polygon;
    }

    public function countryCode(): ?string
    {
        return $this->countryCode;
    }

    public function adminArea(): ?string
    {
        return $this->adminArea;
    }

    public function feeMinor(): ?int
    {
        return $this->feeMinor;
    }

    public function minOrderMinor(): ?int
    {
        return $this->minOrderMinor;
    }

    public function isRestricted(): bool
    {
        return $this->isRestricted;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
