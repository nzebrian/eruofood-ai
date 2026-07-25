<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Domain\Exception\CatalogNotFound;
use EruoFood\Catalog\Domain\Recipe\FavouriteRepository;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Shared\Domain\Paginated;

/** Favourite recipes for the authenticated user. */
final readonly class FavouriteService
{
    public function __construct(
        private FavouriteRepository $favourites,
        private RecipeRepository $recipes,
    ) {
    }

    public function add(string $userId, string $recipeId): void
    {
        if ($this->recipes->findById($recipeId) === null) {
            throw CatalogNotFound::of('recipe', $recipeId);
        }
        $this->favourites->add($userId, $recipeId);
    }

    public function remove(string $userId, string $recipeId): void
    {
        $this->favourites->remove($userId, $recipeId);
    }

    public function isFavourited(string $userId, string $recipeId): bool
    {
        return $this->favourites->exists($userId, $recipeId);
    }

    /**
     * @return Paginated<Recipe>
     */
    public function list(string $userId, int $page, int $perPage): Paginated
    {
        return $this->favourites->forUser($userId, max(1, $page), min(60, max(1, $perPage)));
    }
}
