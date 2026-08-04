<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\FavouriteService;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Favourite recipes for the authenticated user. */
final readonly class FavouriteController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private FavouriteService $favourites,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->favourites->list(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Recipe $r): array => $this->presenter->recipeSummary($r));
    }

    public function store(Request $request, string $recipeId): JsonResponse
    {
        $this->favourites->add($this->currentUserId($request), $recipeId);

        return $this->data(['message' => 'Added to favourites.'], 201);
    }

    public function destroy(Request $request, string $recipeId): JsonResponse
    {
        $this->favourites->remove($this->currentUserId($request), $recipeId);

        return new JsonResponse(null, 204);
    }
}
