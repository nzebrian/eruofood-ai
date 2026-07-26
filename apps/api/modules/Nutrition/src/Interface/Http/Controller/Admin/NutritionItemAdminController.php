<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller\Admin;

use EruoFood\Nutrition\Application\Input\NutritionItemInput;
use EruoFood\Nutrition\Application\Service\NutritionItemService;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\NutritionItemRequest;
use Illuminate\Http\JsonResponse;

/** Admin CRUD for the nutrition database (RBAC: admin). */
final readonly class NutritionItemAdminController
{
    use RespondsWithData;

    public function __construct(
        private NutritionItemService $items,
        private NutritionPresenter $presenter,
    ) {
    }

    public function store(NutritionItemRequest $request): JsonResponse
    {
        $item = $this->items->create(NutritionItemInput::fromArray($request->validated()));

        return $this->data($this->presenter->item($item), 201);
    }

    public function update(NutritionItemRequest $request, string $id): JsonResponse
    {
        $item = $this->items->update($id, NutritionItemInput::fromArray($request->validated()));

        return $this->data($this->presenter->item($item));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->items->delete($id);

        return new JsonResponse(null, 204);
    }
}
