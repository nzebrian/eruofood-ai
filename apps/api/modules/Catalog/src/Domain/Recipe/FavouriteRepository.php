<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use EruoFood\Shared\Domain\Paginated;

/** Persists user ↔ recipe favourite links. */
interface FavouriteRepository
{
    public function add(string $userId, string $recipeId): void;

    public function remove(string $userId, string $recipeId): void;

    public function exists(string $userId, string $recipeId): bool;

    /**
     * Recipes the user has favourited.
     *
     * @return Paginated<Recipe>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated;

    /**
     * @param list<string> $recipeIds
     * @return list<string> the subset the user has favourited
     */
    public function filterFavourited(string $userId, array $recipeIds): array;
}
