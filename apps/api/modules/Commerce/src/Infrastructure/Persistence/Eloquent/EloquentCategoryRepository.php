<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Catalog\CategoryRepository;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\CategoryModel;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Support\Str;

final class EloquentCategoryRepository implements CategoryRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Category
    {
        $m = CategoryModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function slugExists(string $slug): bool
    {
        return CategoryModel::query()->where('slug', $slug)->exists();
    }

    public function all(?ProductKind $kind = null, ?GroceryDepartment $department = null): array
    {
        $query = CategoryModel::query();
        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }
        if ($department !== null) {
            $query->where('department', $department->value);
        }

        return array_map(
            fn (CategoryModel $m): Category => $this->toDomain($m),
            $query->orderBy('sort_order')->orderBy('name')->get()->all(),
        );
    }

    public function save(Category $category): void
    {
        $model = CategoryModel::query()->find($category->id()) ?? new CategoryModel();
        $model->id = $category->id();
        $model->name = $category->name();
        $model->slug = (string) $category->slug();
        $model->kind = $category->kind()->value;
        $model->department = $category->department()?->value;
        $model->parent_id = $category->parentId();
        $model->sort_order = $category->sortOrder();
        $model->save();
    }

    public function delete(string $id): void
    {
        CategoryModel::query()->where('id', $id)->delete();
    }

    private function toDomain(CategoryModel $m): Category
    {
        return Category::reconstitute(
            id: $m->id,
            name: $m->name,
            slug: new Slug($m->slug),
            kind: ProductKind::from($m->kind),
            department: $m->department !== null ? GroceryDepartment::from($m->department) : null,
            parentId: $m->parent_id,
            sortOrder: $m->sort_order,
        );
    }
}
