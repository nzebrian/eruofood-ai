<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CartService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\CartItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The authenticated user's shopping cart. */
final readonly class CartController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private CartService $carts,
        private CommercePresenter $presenter,
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
            (string) $data['product_id'],
            isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
            (int) $data['quantity'],
        );

        return $this->data($this->presenter->cart($cart), 201);
    }

    public function updateItem(CartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cart = $this->carts->setQuantity(
            $this->currentUserId($request),
            (string) $data['product_id'],
            isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
            (int) $data['quantity'],
        );

        return $this->data($this->presenter->cart($cart));
    }

    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'variant_sku' => ['nullable', 'string', 'max:60'],
        ]);
        $cart = $this->carts->removeItem(
            $this->currentUserId($request),
            (string) $data['product_id'],
            isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
        );

        return $this->data($this->presenter->cart($cart));
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40']]);
        $cart = $this->carts->applyCoupon(
            $this->currentUserId($request),
            isset($data['code']) ? (string) $data['code'] : null,
        );

        return $this->data($this->presenter->cart($cart));
    }

    public function clear(Request $request): JsonResponse
    {
        $this->carts->clear($this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
