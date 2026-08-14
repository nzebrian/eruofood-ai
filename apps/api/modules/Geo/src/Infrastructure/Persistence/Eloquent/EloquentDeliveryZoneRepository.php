<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\ZoneType;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZone;
use EruoFood\Geo\Domain\Zone\DeliveryZoneRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\DeliveryZoneModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see DeliveryZone}.
 *
 * The bounding box stored on each row is a cache of the zone's own geometry,
 * recomputed on every save. It exists so `candidatesFor` can rule most zones
 * out with an indexed comparison before any point-in-polygon arithmetic runs —
 * and because it is derived, never entered, it cannot drift away from the shape
 * it describes.
 *
 * Candidates come back ordered by priority, lowest first, which is what lets a
 * narrow exclusion be consulted before the broad service area it sits inside.
 * The caller still asks each candidate whether it truly contains the point: a
 * box is a prefilter, not an answer.
 */
final class EloquentDeliveryZoneRepository implements DeliveryZoneRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?DeliveryZone
    {
        $model = DeliveryZoneModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forOwner(string $ownerType, ?string $ownerId, bool $activeOnly = true): array
    {
        $query = DeliveryZoneModel::query()->where('owner_type', $ownerType);

        // A platform-wide zone has no owner, so a null owner is a real filter
        // rather than "any owner" — `where('owner_id', null)` would match
        // nothing at all in SQL.
        $ownerId === null
            ? $query->whereNull('owner_id')
            : $query->where('owner_id', $ownerId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $models = $query->orderBy('priority')->orderBy('name')->get();

        return array_values(array_map(fn (DeliveryZoneModel $m): DeliveryZone => $this->toDomain($m), $models->all()));
    }

    public function candidatesFor(Coordinates $point, ?string $ownerType = null, ?string $ownerId = null): array
    {
        $query = DeliveryZoneModel::query()->where('is_active', true);

        if ($ownerType !== null) {
            $query->where('owner_type', $ownerType);

            if ($ownerId !== null) {
                $query->where('owner_id', $ownerId);
            }
        }

        $query->where(function (Builder $q) use ($point): void {
            // Radius and polygon zones: the point must fall inside the stored
            // box.
            $q->where(function (Builder $box) use ($point): void {
                $box->where('bbox_min_lat', '<=', $point->latitude)
                    ->where('bbox_max_lat', '>=', $point->latitude)
                    ->where('bbox_min_lon', '<=', $point->longitude)
                    ->where('bbox_max_lon', '>=', $point->longitude);
            });

            // Administrative zones have no geometry — a point cannot tell you
            // which LGA it is in. They are matched on the address's own fields
            // by `containsAdministratively`, so they must survive the box
            // prefilter rather than being excluded by having no box.
            $q->orWhere('zone_type', ZoneType::Administrative->value);
        });

        $models = $query->orderBy('priority')->orderBy('id')->get();

        return array_values(array_map(fn (DeliveryZoneModel $m): DeliveryZone => $this->toDomain($m), $models->all()));
    }

    public function save(DeliveryZone $zone): void
    {
        $centre = $zone->centre();
        $box = $zone->boundingBox();

        $attributes = [
            'owner_type' => $zone->ownerType(),
            'owner_id' => $zone->ownerId(),
            'name' => $zone->name(),
            'zone_type' => $zone->zoneType()->value,
            'centre_latitude' => $centre?->latitude,
            'centre_longitude' => $centre?->longitude,
            'radius_metres' => $zone->radiusMetres(),
            'polygon' => $zone->polygonPoints() === null ? null : json_encode($zone->polygonPoints()),
            'bbox_min_lat' => $box['minLat'] ?? null,
            'bbox_max_lat' => $box['maxLat'] ?? null,
            'bbox_min_lon' => $box['minLon'] ?? null,
            'bbox_max_lon' => $box['maxLon'] ?? null,
            'country_code' => $zone->countryCode(),
            'admin_area' => $zone->adminArea(),
            'fee_minor' => $zone->feeMinor(),
            'min_order_minor' => $zone->minOrderMinor(),
            'is_restricted' => $zone->isRestricted(),
            'is_active' => $zone->isActive(),
            'priority' => $zone->priority(),
            'updated_at' => $zone->updatedAt(),
        ];

        $exists = DeliveryZoneModel::query()->whereKey($zone->id())->exists();

        if (! $exists) {
            DeliveryZoneModel::query()->insert($attributes + [
                'id' => $zone->id(),
                'created_at' => $zone->createdAt(),
            ]);

            return;
        }

        DeliveryZoneModel::query()->whereKey($zone->id())->update($attributes);
    }

    private function toDomain(DeliveryZoneModel $m): DeliveryZone
    {
        return DeliveryZone::reconstitute(
            id: $m->id,
            ownerType: $m->owner_type,
            ownerId: $m->owner_id,
            name: $m->name,
            zoneType: ZoneType::from($m->zone_type),
            centre: Coordinates::tryFromMixed($m->centre_latitude, $m->centre_longitude),
            radiusMetres: $m->radius_metres,
            polygon: $this->toPolygon($m->polygon),
            countryCode: $m->country_code,
            adminArea: $m->admin_area,
            feeMinor: $m->fee_minor,
            minOrderMinor: $m->min_order_minor,
            isRestricted: $m->is_restricted,
            isActive: $m->is_active,
            priority: $m->priority,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    /**
     * Rebuild the ring from jsonb, discarding anything malformed.
     *
     * A zone with a broken polygon becomes a zone that contains nothing, which
     * is the safe direction: it withdraws an offer to deliver rather than
     * silently extending one over an area nobody drew.
     *
     * @param array<int, mixed>|null $stored
     * @return list<array{0: float, 1: float}>|null
     */
    private function toPolygon(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $points = [];

        foreach ($stored as $pair) {
            if (! is_array($pair) || ! isset($pair[0], $pair[1]) || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
                return null;
            }

            $points[] = [(float) $pair[0], (float) $pair[1]];
        }

        return $points === [] ? null : $points;
    }
}
