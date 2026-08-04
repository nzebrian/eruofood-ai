<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\WishlistService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The authenticated user's wishlist. */
final readonly class WishlistController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private WishlistService $wishlists,
        private CommercePresenter $presenter,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        return $this->data([
            'product_ids' => $this->wishlists->get($userId)->productIds(),
            'products' => array_map(
                fn (Product $p): array => $this->presenter->productSummary($p),
                $this->wishlists->products($userId),
            ),
        ]);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'uuid']]);
        $wishlist = $this->wishlists->add($this->currentUserId($request), (string) $data['product_id']);

        return $this->data($this->presenter->wishlist($wishlist), 201);
    }

    public function remove(Request $request, string $productId): JsonResponse
    {
        $wishlist = $this->wishlists->remove($this->currentUserId($request), $productId);

        return $this->data($this->presenter->wishlist($wishlist));
    }
}
