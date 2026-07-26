<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\CartService;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\CartItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The authenticated user's shopping cart. */
final readonly class CartController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private CartService $carts,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->data($this->presenter->cart($this->carts->get($this->currentUserId($request))));
    }

    public function add(CartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cart = $this->carts->addItem(
            $this->currentUserId($request),
            (string) $data['menu_item_id'],
            isset($data['variant_name']) ? (string) $data['variant_name'] : null,
            (int) $data['quantity'],
        );

        return $this->data($this->presenter->cart($cart), 201);
    }

    public function updateItem(CartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cart = $this->carts->setQuantity(
            $this->currentUserId($request),
            (string) $data['menu_item_id'],
            isset($data['variant_name']) ? (string) $data['variant_name'] : null,
            (int) $data['quantity'],
        );

        return $this->data($this->presenter->cart($cart));
    }

    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'uuid'],
            'variant_name' => ['nullable', 'string', 'max:80'],
        ]);
        $cart = $this->carts->removeItem(
            $this->currentUserId($request),
            (string) $data['menu_item_id'],
            isset($data['variant_name']) ? (string) $data['variant_name'] : null,
        );

        return $this->data($this->presenter->cart($cart));
    }

    public function clear(Request $request): JsonResponse
    {
        $this->carts->clear($this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
