<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Crm;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see CustomerProfile} aggregate. */
interface CustomerProfileRepository
{
    public function findByUserId(string $userId): ?CustomerProfile;

    /**
     * @return Paginated<CustomerProfile>
     */
    public function search(?string $term, ?CustomerSegment $segment, int $page, int $perPage): Paginated;

    /**
     * Count of profiles per segment, for segmentation dashboards.
     *
     * @return array<string, int>
     */
    public function segmentCounts(): array;

    public function save(CustomerProfile $profile): void;
}
