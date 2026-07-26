<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use EruoFood\Marketplace\Domain\Menu\MenuCategory;
use EruoFood\Marketplace\Domain\Menu\MenuCategoryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\MenuCategoryModel;
use Illuminate\Support\Str;

final class EloquentMenuCategoryRepository implements MenuCategoryRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?MenuCategory
    {
        $m = MenuCategoryModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forVendor(string $vendorId): array
    {
        return array_map(
            fn (MenuCategoryModel $m): MenuCategory => $this->toDomain($m),
            MenuCategoryModel::query()->where('vendor_id', $vendorId)->orderBy('sort_order')->orderBy('name')->get()->all(),
        );
    }

    public function save(MenuCategory $category): void
    {
        $model = MenuCategoryModel::query()->find($category->id()) ?? new MenuCategoryModel();
        $model->id = $category->id();
        $model->vendor_id = $category->vendorId();
        $model->name = $category->name();
        $model->sort_order = $category->sortOrder();
        $model->save();
    }

    public function delete(string $id): void
    {
        MenuCategoryModel::query()->where('id', $id)->delete();
    }

    private function toDomain(MenuCategoryModel $m): MenuCategory
    {
        return MenuCategory::create($m->id, $m->vendor_id, $m->name, $m->sort_order);
    }
}
