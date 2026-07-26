<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Vendor;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for vendors (Repository Pattern). */
interface VendorRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Vendor;

    public function findBySlug(string $slug): ?Vendor;

    public function slugExists(string $slug): bool;

    /**
     * Search verified vendors.
     *
     * @return Paginated<Vendor>
     */
    public function search(VendorSearchCriteria $criteria, int $page, int $perPage): Paginated;

    /**
     * All vendors owned by a user (any status).
     *
     * @return list<Vendor>
     */
    public function forOwner(string $ownerUserId): array;

    public function save(Vendor $vendor): void;
}
