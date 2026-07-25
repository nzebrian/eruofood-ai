<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Domain\Exception\CatalogNotFound;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeReview;
use EruoFood\Catalog\Domain\Recipe\RecipeReviewRepository;
use EruoFood\Catalog\Domain\ValueObject\Rating;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;

/** Recipe ratings & reviews; keeps the recipe's denormalised rating in sync. */
final readonly class RecipeReviewService
{
    public function __construct(
        private RecipeReviewRepository $reviews,
        private RecipeRepository $recipes,
        private Clock $clock,
    ) {
    }

    /** Add a review, or update the caller's existing one (one review per user). */
    public function submit(string $recipeId, string $userId, int $rating, ?string $comment): RecipeReview
    {
        $recipe = $this->recipes->findById($recipeId) ?? throw CatalogNotFound::of('recipe', $recipeId);
        $ratingVo = new Rating($rating);

        $review = $this->reviews->findByRecipeAndUser($recipeId, $userId);
        if ($review !== null) {
            $review->update($ratingVo, $comment);
        } else {
            $review = RecipeReview::create(
                id: $this->reviews->nextIdentity(),
                recipeId: $recipeId,
                userId: $userId,
                rating: $ratingVo,
                comment: $comment,
                createdAt: $this->clock->now(),
            );
        }
        $this->reviews->save($review);

        // Recompute and persist the recipe's rating summary.
        $summary = $this->reviews->summaryForRecipe($recipeId);
        $recipe->applyRatingSummary($summary['average'], $summary['count']);
        $this->recipes->save($recipe);

        return $review;
    }

    /**
     * @return Paginated<RecipeReview>
     */
    public function list(string $recipeId, int $page, int $perPage): Paginated
    {
        return $this->reviews->forRecipe($recipeId, max(1, $page), min(60, max(1, $perPage)));
    }
}
