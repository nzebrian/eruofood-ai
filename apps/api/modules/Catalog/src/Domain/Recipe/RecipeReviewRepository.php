<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use EruoFood\Shared\Domain\Paginated;

interface RecipeReviewRepository
{
    public function nextIdentity(): string;

    public function findByRecipeAndUser(string $recipeId, string $userId): ?RecipeReview;

    /**
     * @return Paginated<RecipeReview>
     */
    public function forRecipe(string $recipeId, int $page, int $perPage): Paginated;

    public function save(RecipeReview $review): void;

    /** @return array{average: float, count: int} */
    public function summaryForRecipe(string $recipeId): array;
}
