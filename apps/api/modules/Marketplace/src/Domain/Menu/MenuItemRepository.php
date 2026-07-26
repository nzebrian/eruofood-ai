<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Menu;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for menu items (Repository Pattern). */
interface MenuItemRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?MenuItem;

    /**
     * @param list<string> $ids
     * @return array<string, MenuItem> keyed by id
     */
    public function findMany(array $ids): array;

    /**
     * A vendor's menu items (optionally only available ones).
     *
     * @return list<MenuItem>
     */
    public function forVendor(string $vendorId, bool $onlyAvailable = false): array;

    /**
     * Search available items across verified vendors.
     *
     * @return Paginated<MenuItem>
     */
    public function search(?string $term, ?string $vendorId, bool $featuredOnly, int $page, int $perPage): Paginated;

    public function save(MenuItem $item): void;

    public function delete(string $id): void;
}
