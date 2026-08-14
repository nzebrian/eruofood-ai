<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Zone;

use EruoFood\Geo\Domain\ValueObject\Coordinates;

/** Persistence port for {@see DeliveryZone}. */
interface DeliveryZoneRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?DeliveryZone;

    /** @return list<DeliveryZone> */
    public function forOwner(string $ownerType, ?string $ownerId, bool $activeOnly = true): array;

    /**
     * Zones whose bounding box covers a point, most specific first.
     *
     * The bounding box is a prefilter only — the caller still asks each
     * candidate whether it truly contains the point. Ordering by priority is
     * what lets a narrow exclusion be consulted before the broad service area
     * it sits inside.
     *
     * @return list<DeliveryZone>
     */
    public function candidatesFor(Coordinates $point, ?string $ownerType = null, ?string $ownerId = null): array;

    public function save(DeliveryZone $zone): void;
}
