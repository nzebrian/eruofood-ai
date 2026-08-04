<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Input\MenuItemInput;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\MenuService;
use EruoFood\Marketplace\Domain\ValueObject\Promotion;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\MenuItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-side menu management: categories, items, availability, promotions, stock, AI copy. */
final readonly class MenuManagementController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private MenuService $menu,
        private MarketplacePresenter $presenter,
        private string $currency,
    ) {
    }

    public function storeCategory(Request $request, string $vendorId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = $this->menu->createCategory(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $vendorId,
            (string) $validated['name'],
            (int) ($validated['sort_order'] ?? 0),
        );

        return $this->data($this->presenter->category($category), 201);
    }

    public function deleteCategory(Request $request, string $categoryId): JsonResponse
    {
        $this->menu->deleteCategory($this->currentUserId($request), $this->actorIsAdmin($request), $categoryId);

        return new JsonResponse(null, 204);
    }

    public function storeItem(MenuItemRequest $request, string $vendorId): JsonResponse
    {
        $item = $this->menu->createItem(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $vendorId,
            MenuItemInput::fromArray($request->validated(), $this->currency),
        );

        return $this->data($this->presenter->menuItem($item), 201);
    }

    public function updateItem(MenuItemRequest $request, string $itemId): JsonResponse
    {
        $item = $this->menu->updateItem(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $itemId,
            MenuItemInput::fromArray($request->validated(), $this->currency),
        );

        return $this->data($this->presenter->menuItem($item));
    }

    public function deleteItem(Request $request, string $itemId): JsonResponse
    {
        $this->menu->deleteItem($this->currentUserId($request), $this->actorIsAdmin($request), $itemId);

        return new JsonResponse(null, 204);
    }

    public function setAvailability(Request $request, string $itemId): JsonResponse
    {
        $available = $request->boolean('available');
        $item = $this->menu->setAvailability($this->currentUserId($request), $this->actorIsAdmin($request), $itemId, $available);

        return $this->data($this->presenter->menuItem($item));
    }

    public function setFeatured(Request $request, string $itemId): JsonResponse
    {
        $item = $this->menu->setFeatured($this->currentUserId($request), $this->actorIsAdmin($request), $itemId, $request->boolean('featured'));

        return $this->data($this->presenter->menuItem($item));
    }

    public function setPromotion(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:percentage,fixed'],
            'value' => ['nullable', 'integer', 'min:0'],
        ]);
        $promotion = isset($validated['type'])
            ? new Promotion((string) $validated['type'], (int) ($validated['value'] ?? 0))
            : null;

        $item = $this->menu->setPromotion($this->currentUserId($request), $this->actorIsAdmin($request), $itemId, $promotion);

        return $this->data($this->presenter->menuItem($item));
    }

    public function restock(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate(['stock' => ['required', 'integer', 'min:0']]);
        $item = $this->menu->restock($this->currentUserId($request), $this->actorIsAdmin($request), $itemId, (int) $validated['stock']);

        return $this->data($this->presenter->menuItem($item));
    }

    public function describe(Request $request, string $itemId): JsonResponse
    {
        $item = $this->menu->generateDescription($this->currentUserId($request), $this->actorIsAdmin($request), $itemId);

        return $this->data($this->presenter->menuItem($item));
    }
}
