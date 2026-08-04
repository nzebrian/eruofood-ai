<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Ingredient\Ingredient;
use EruoFood\Catalog\Domain\Ingredient\IngredientRepository;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\IngredientModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Support\Str;

final class EloquentIngredientRepository implements IngredientRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Ingredient
    {
        $model = IngredientModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function existsBySlug(string $slug): bool
    {
        return IngredientModel::query()->where('slug', $slug)->exists();
    }

    public function search(?string $term, int $page, int $perPage): Paginated
    {
        $paginator = IngredientModel::query()
            ->when($term, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower((string) $term).'%']))
            ->orderBy('name')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (IngredientModel $m): Ingredient => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Ingredient $ingredient): void
    {
        $model = IngredientModel::query()->find($ingredient->id()) ?? new IngredientModel();
        $model->id = $ingredient->id();
        $model->name = $ingredient->name();
        $model->slug = $ingredient->slug()->value;
        $model->description = $ingredient->description();
        $model->local_names = array_map(static fn (LocalName $l): array => $l->toArray(), $ingredient->localNames());
        $model->nutrition = $ingredient->nutritionPer100g()?->toArray();
        $model->save();
    }

    public function delete(string $id): void
    {
        IngredientModel::query()->whereKey($id)->delete();
    }

    private function toDomain(IngredientModel $m): Ingredient
    {
        return Ingredient::reconstitute(
            id: $m->id,
            name: $m->name,
            slug: new Slug($m->slug),
            description: $m->description,
            localNames: array_values(array_map(
                static fn (array $l): LocalName => LocalName::fromArray($l),
                $m->local_names ?? [],
            )),
            nutritionPer100g: $m->nutrition !== null ? NutritionalInfo::fromArray($m->nutrition) : null,
        );
    }
}
