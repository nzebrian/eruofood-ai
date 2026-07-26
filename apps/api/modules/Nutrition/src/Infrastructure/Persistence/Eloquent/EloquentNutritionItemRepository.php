<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent;

use EruoFood\Nutrition\Domain\Item\NutritionItem;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Nutrition\Domain\ValueObject\ServingSize;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model\NutritionItemModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Support\Str;

final class EloquentNutritionItemRepository implements NutritionItemRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?NutritionItem
    {
        $model = NutritionItemModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function slugExists(string $slug): bool
    {
        return NutritionItemModel::query()->where('slug', $slug)->exists();
    }

    public function findMany(array $ids): array
    {
        $models = NutritionItemModel::query()->whereIn('id', $ids)->get();

        $out = [];
        foreach ($models as $model) {
            $out[$model->id] = $this->toDomain($model);
        }

        return $out;
    }

    public function search(?string $term, ?string $category, int $page, int $perPage): Paginated
    {
        $query = NutritionItemModel::query();

        if ($term !== null && $term !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
        }
        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        $paginator = $query->orderBy('name')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (NutritionItemModel $m): NutritionItem => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(NutritionItem $item): void
    {
        $model = NutritionItemModel::query()->find($item->id()) ?? new NutritionItemModel();
        $model->id = $item->id();
        $model->name = $item->name();
        $model->slug = (string) $item->slug();
        $model->category = $item->category();
        $model->serving_label = $item->servingSize()->label;
        $model->serving_grams = $item->servingSize()->grams;
        $model->nutrition = $item->facts()->toArray();
        $model->food_id = $item->foodId();
        $model->save();
    }

    public function delete(string $id): void
    {
        NutritionItemModel::query()->where('id', $id)->delete();
    }

    private function toDomain(NutritionItemModel $m): NutritionItem
    {
        return NutritionItem::create(
            id: $m->id,
            name: $m->name,
            slug: new Slug($m->slug),
            category: $m->category,
            servingSize: new ServingSize($m->serving_label, $m->serving_grams),
            facts: NutritionFacts::fromArray($m->nutrition ?? []),
            foodId: $m->food_id,
        );
    }
}
