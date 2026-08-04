<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Menu;

/** Persistence port for menu categories (Repository Pattern). */
interface MenuCategoryRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?MenuCategory;

    /**
     * A vendor's categories, sort order then name.
     *
     * @return list<MenuCategory>
     */
    public function forVendor(string $vendorId): array;

    public function save(MenuCategory $category): void;

    public function delete(string $id): void;
}
