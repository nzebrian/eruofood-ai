<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Recipe\FavouriteRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\FavouriteModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final readonly class EloquentFavouriteRepository implements FavouriteRepository
{
    public function __construct(private RecipeRepository $recipes)
    {
    }

    public function add(string $userId, string $recipeId): void
    {
        FavouriteModel::query()->updateOrCreate(
            ['user_id' => $userId, 'recipe_id' => $recipeId],
            ['id' => (string) Str::orderedUuid()],
        );
    }

    public function remove(string $userId, string $recipeId): void
    {
        FavouriteModel::query()
            ->where('user_id', $userId)
            ->where('recipe_id', $recipeId)
            ->delete();
    }

    public function exists(string $userId, string $recipeId): bool
    {
        return FavouriteModel::query()
            ->where('user_id', $userId)
            ->where('recipe_id', $recipeId)
            ->exists();
    }

    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = FavouriteModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        /** @var list<string> $recipeIds */
        $recipeIds = array_map(static fn (FavouriteModel $m): string => $m->recipe_id, $paginator->items());
        $recipes = $this->recipes->findManyByIds($recipeIds);

        // Preserve the favourite ordering.
        $byId = [];
        foreach ($recipes as $recipe) {
            $byId[$recipe->id()] = $recipe;
        }
        $ordered = [];
        foreach ($recipeIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return new Paginated($ordered, $paginator->total(), $page, $perPage);
    }

    public function filterFavourited(string $userId, array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }

        return array_values(FavouriteModel::query()
            ->where('user_id', $userId)
            ->whereIn('recipe_id', $recipeIds)
            ->pluck('recipe_id')
            ->all());
    }
}
