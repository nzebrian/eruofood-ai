<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Service\NutritionItemService;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Domain\Item\NutritionItem;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public read access to the nutrition database. */
final readonly class NutritionItemController
{
    use RespondsWithData;

    public function __construct(
        private NutritionItemService $items,
        private NutritionPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->items->search(
            ((string) $request->string('q')) ?: null,
            ((string) $request->string('category')) ?: null,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (NutritionItem $i): array => $this->presenter->item($i));
    }

    public function show(string $id): JsonResponse
    {
        return $this->data($this->presenter->item($this->items->get($id)));
    }
}
