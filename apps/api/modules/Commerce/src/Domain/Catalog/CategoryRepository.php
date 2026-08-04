<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;

/** Persistence port for the {@see Category} aggregate. */
interface CategoryRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Category;

    public function slugExists(string $slug): bool;

    /** @return list<Category> */
    public function all(?ProductKind $kind = null, ?GroceryDepartment $department = null): array;

    public function save(Category $category): void;

    public function delete(string $id): void;
}
