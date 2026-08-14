<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use EruoFood\Geo\Application\Service\DeliveryZoneService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\Zone\DeliveryZone;
use EruoFood\Geo\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Geo\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant delivery zones.
 *
 * Ownership is taken from the route and re-checked on the loaded zone, so a
 * merchant cannot rename or deactivate the platform's zones or another
 * merchant's — an id in a URL is not a claim to what it points at.
 *
 * The serviceability check is public on purpose: a customer needs to know
 * whether an address can be delivered to *before* they build a basket, and
 * answering it reveals nothing beyond what the merchant already advertises.
 */
final readonly class DeliveryZoneController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private DeliveryZoneService $zones,
        private GeoPresenter $presenter,
    ) {
    }

    public function index(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        return $this->data(array_map(
            fn (DeliveryZone $z): array => $this->presenter->zone($z),
            $this->zones->forOwner($ownerType, $ownerId),
        ));
    }

    public function store(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'zone_type' => ['required', 'string', 'in:radius,polygon'],
            'latitude' => ['required_if:zone_type,radius', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:zone_type,radius', 'numeric', 'between:-180,180'],
            'radius_metres' => ['required_if:zone_type,radius', 'integer', 'min:1', 'max:200000'],
            // GeoJSON order: [longitude, latitude]. The reverse of how
            // coordinates are spoken, and the service validates each pair
            // rather than trusting the shape.
            'polygon' => ['required_if:zone_type,polygon', 'array', 'min:3', 'max:500'],
            'fee_minor' => ['nullable', 'integer', 'min:0'],
            'is_restricted' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $zone = $data['zone_type'] === 'radius'
            ? $this->zones->createRadiusZone(
                $ownerType,
                $ownerId,
                (string) $data['name'],
                Coordinates::fromMixed($data['latitude'], $data['longitude']),
                (int) $data['radius_metres'],
                isset($data['fee_minor']) ? (int) $data['fee_minor'] : null,
                (int) ($data['priority'] ?? 100),
            )
            : $this->zones->createPolygonZone(
                $ownerType,
                $ownerId,
                (string) $data['name'],
                $this->zones->parsePolygon((array) $data['polygon']),
                isset($data['fee_minor']) ? (int) $data['fee_minor'] : null,
                (bool) ($data['is_restricted'] ?? false),
                // A restriction that sorts after the area it sits inside never
                // fires, so an exclusion defaults to being consulted first.
                (int) ($data['priority'] ?? (($data['is_restricted'] ?? false) ? 10 : 100)),
            );

        return $this->data($this->presenter->zone($zone), 201);
    }

    public function update(Request $request, string $ownerType, string $ownerId, string $zoneId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $this->zones->rename($zoneId, $ownerType, $ownerId, (string) $data['name']);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $this->zones->setActive($zoneId, $ownerType, $ownerId, (bool) $data['is_active']);
        }

        return $this->data($this->presenter->zone($this->zones->getOwned($zoneId, $ownerType, $ownerId)));
    }

    /**
     * Can this merchant deliver here?
     *
     * Answered before a customer builds a basket rather than at checkout, which
     * is where the pre-M25 platform would have discovered it.
     */
    public function check(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $point = Coordinates::fromMixed($data['latitude'], $data['longitude']);
        $zone = $this->zones->zoneFor($point, $ownerType, $ownerId);

        return $this->data([
            'is_serviceable' => $zone !== null && ! $zone->isRestricted(),
            // Named so a customer can be told *why* — "outside our area" and
            // "we don't deliver to that estate" are different messages.
            'zone' => $zone === null ? null : [
                'id' => $zone->id(),
                'name' => $zone->name(),
                'is_restricted' => $zone->isRestricted(),
                'fee_minor' => $zone->feeMinor(),
                'min_order_minor' => $zone->minOrderMinor(),
            ],
        ]);
    }
}
