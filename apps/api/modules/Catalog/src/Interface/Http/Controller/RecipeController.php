<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Input\RecipeInput;
use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\FavouriteService;
use EruoFood\Catalog\Application\Service\RecipeReviewService;
use EruoFood\Catalog\Application\Service\RecipeService;
use EruoFood\Catalog\Domain\Enum\Difficulty;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeReview;
use EruoFood\Catalog\Domain\Recipe\RecipeSearchCriteria;
use EruoFood\Catalog\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Catalog\Interface\Http\Request\RecipeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recipes: public browse/detail + authenticated CRUD (owner or admin). */
final readonly class RecipeController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private RecipeService $recipes,
        private RecipeReviewService $reviews,
        private FavouriteService $favourites,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $criteria = new RecipeSearchCriteria(
            term: ((string) $request->string('q')) ?: null,
            foodId: ((string) $request->string('food_id')) ?: null,
            difficulty: $request->filled('difficulty') ? Difficulty::tryFrom((string) $request->string('difficulty')) : null,
            tag: ((string) $request->string('tag')) ?: null,
            maxTotalMinutes: $request->filled('max_minutes') ? (int) $request->integer('max_minutes') : null,
            sort: (string) (((string) $request->string('sort')) ?: 'recent'),
        );

        $page = $this->recipes->search($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Recipe $r): array => $this->presenter->recipeSummary($r));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $recipe = $this->recipes->getBySlug($slug);
        $favourited = $this->favouritedIds($request, [$recipe->id()]);

        return $this->data($this->presenter->recipe($recipe, $favourited));
    }

    public function byFood(Request $request, string $foodId): JsonResponse
    {
        $criteria = new RecipeSearchCriteria(foodId: $foodId);
        $page = $this->recipes->search($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Recipe $r): array => $this->presenter->recipeSummary($r));
    }

    public function related(string $id): JsonResponse
    {
        $related = array_map(
            fn (Recipe $r): array => $this->presenter->recipeSummary($r),
            $this->recipes->related($id),
        );

        return $this->data($related);
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $page = $this->reviews->list($id, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (RecipeReview $r): array => $this->presenter->review($r));
    }

    public function versions(string $id): JsonResponse
    {
        return $this->data($this->recipes->versionHistory($id));
    }

    // ---- Authenticated CRUD -------------------------------------------------

    public function store(RecipeRequest $request): JsonResponse
    {
        $recipe = $this->recipes->create(
            $this->currentUserId($request),
            RecipeInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->recipe($recipe), 201);
    }

    public function update(RecipeRequest $request, string $id): JsonResponse
    {
        $recipe = $this->recipes->update(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            RecipeInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->recipe($recipe));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->recipes->delete($this->currentUserId($request), $this->actorIsAdmin($request), $id);

        return new JsonResponse(null, 204);
    }

    /**
     * @param list<string> $recipeIds
     * @return list<string>
     */
    private function favouritedIds(Request $request, array $recipeIds): array
    {
        $userId = $this->currentUserIdOrNull($request);
        if ($userId === null) {
            return [];
        }

        return array_values(array_filter(
            $recipeIds,
            fn (string $id): bool => $this->favourites->isFavourited($userId, $id),
        ));
    }
}
