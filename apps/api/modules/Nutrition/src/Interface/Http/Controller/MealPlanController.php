<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\MealPlanInput;
use EruoFood\Nutrition\Application\Service\MealPlanService;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Application\Service\ShoppingListService;
use EruoFood\Nutrition\Domain\Plan\MealPlan;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\MealPlanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daily / weekly / monthly meal plans, portion adjustment and shopping lists. */
final readonly class MealPlanController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private MealPlanService $plans,
        private ShoppingListService $shopping,
        private NutritionPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->plans->list(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (MealPlan $p): array => $this->presenter->mealPlan($p));
    }

    public function store(MealPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create(
            $this->currentUserId($request),
            MealPlanInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->mealPlan($plan), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->data($this->presenter->mealPlan($this->plans->get($this->currentUserId($request), $id)));
    }

    /** Scale every portion in the plan by a factor. */
    public function adjust(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['factor' => ['required', 'numeric', 'min:0.1', 'max:10']]);
        $plan = $this->plans->adjustPortions($this->currentUserId($request), $id, (float) $validated['factor']);

        return $this->data($this->presenter->mealPlan($plan));
    }

    public function shoppingList(Request $request, string $id): JsonResponse
    {
        $list = $this->shopping->generate($this->currentUserId($request), $id);

        return $this->data($list->toArray());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->plans->delete($this->currentUserId($request), $id);

        return new JsonResponse(null, 204);
    }
}
