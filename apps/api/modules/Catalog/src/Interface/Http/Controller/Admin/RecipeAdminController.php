<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller\Admin;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\RecipeService;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin recipe moderation: publish, archive, set related recipes. */
final readonly class RecipeAdminController
{
    use RespondsWithData;

    public function __construct(
        private RecipeService $recipes,
        private CatalogPresenter $presenter,
    ) {
    }

    public function publish(string $id): JsonResponse
    {
        return $this->data($this->presenter->recipe($this->recipes->publish($id)));
    }

    public function archive(string $id): JsonResponse
    {
        return $this->data($this->presenter->recipe($this->recipes->archive($id)));
    }

    public function setRelated(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'related_recipe_ids' => ['required', 'array'],
            'related_recipe_ids.*' => ['uuid'],
        ]);

        return $this->data($this->presenter->recipe($this->recipes->setRelated($id, $validated['related_recipe_ids'])));
    }
}
