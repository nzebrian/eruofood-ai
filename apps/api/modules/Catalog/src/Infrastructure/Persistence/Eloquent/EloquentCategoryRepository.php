<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Category\Category;
use EruoFood\Catalog\Domain\Category\CategoryRepository;
use EruoFood\Catalog\Domain\Enum\CategoryType;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryModel;
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
        $model = CategoryModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findBySlug(string $slug): ?Category
    {
        $model = CategoryModel::query()->where('slug', $slug)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function existsBySlug(string $slug): bool
    {
        return CategoryModel::query()->where('slug', $slug)->exists();
    }

    public function all(bool $onlyActive = true): array
    {
        return CategoryModel::query()
            ->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CategoryModel $m): Category => $this->toDomain($m))
            ->all();
    }

    public function save(Category $category): void
    {
        $model = CategoryModel::query()->find($category->id()) ?? new CategoryModel();
        $model->id = $category->id();
        $model->name = $category->name();
        $model->slug = $category->slug()->value;
        $model->type = $category->type()->value;
        $model->description = $category->description();
        $model->sort_order = $category->sortOrder();
        $model->active = $category->isActive();
        $model->save();
    }

    public function delete(string $id): void
    {
        CategoryModel::query()->whereKey($id)->delete();
    }

    private function toDomain(CategoryModel $m): Category
    {
        return Category::reconstitute(
            id: $m->id,
            name: $m->name,
            slug: new Slug($m->slug),
            type: CategoryType::from($m->type),
            description: $m->description,
            sortOrder: $m->sort_order,
            active: $m->active,
        );
    }
}
