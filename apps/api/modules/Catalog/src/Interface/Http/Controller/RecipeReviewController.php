<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\RecipeReviewService;
use EruoFood\Catalog\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Submit a rating/review for a recipe (authenticated). */
final readonly class RecipeReviewController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private RecipeReviewService $reviews,
        private CatalogPresenter $presenter,
    ) {
    }

    public function store(Request $request, string $recipeId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $this->reviews->submit(
            $recipeId,
            $this->currentUserId($request),
            (int) $validated['rating'],
            $validated['comment'] ?? null,
        );

        return $this->data($this->presenter->review($review), 201);
    }
}
