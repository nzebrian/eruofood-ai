<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\IngredientService;
use EruoFood\Catalog\Domain\Ingredient\Ingredient;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public ingredient search. */
final readonly class IngredientController
{
    use RespondsWithData;

    public function __construct(
        private IngredientService $ingredients,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->ingredients->search(
            ((string) $request->string('q')) ?: null,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Ingredient $i): array => $this->presenter->ingredient($i));
    }
}
