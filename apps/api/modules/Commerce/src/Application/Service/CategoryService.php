<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Catalog\CategoryRepository;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Product-category management (admin-curated taxonomy incl. grocery departments). */
final readonly class CategoryService
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function create(
        string $name,
        ProductKind $kind,
        ?GroceryDepartment $department,
        ?string $parentId,
        int $sortOrder,
    ): Category {
        $slug = $this->uniqueSlug($name);
        $category = Category::create(
            $this->categories->nextIdentity(),
            $name,
            $slug,
            $kind,
            $department,
            $parentId,
            $sortOrder,
        );
        $this->categories->save($category);

        return $category;
    }

    /** @return list<Category> */
    public function list(?ProductKind $kind = null, ?GroceryDepartment $department = null): array
    {
        return $this->categories->all($kind, $department);
    }

    public function delete(string $id): void
    {
        $this->categories->findById($id) ?? throw CommerceNotFound::of('category', $id);
        $this->categories->delete($id);
    }

    private function uniqueSlug(string $name): Slug
    {
        $base = Slug::fromTitle($name);
        if (! $this->categories->slugExists($base->value)) {
            return $base;
        }
        for ($i = 2; $i <= 50; $i++) {
            $candidate = new Slug($base->value.'-'.$i);
            if (! $this->categories->slugExists($candidate->value)) {
                return $candidate;
            }
        }
        throw new CommerceConflict('Could not generate a unique category slug.');
    }
}
