<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Input\ProductInput;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ProductService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\ProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seller-side product management (create/update/delete, submit, AI describe). */
final readonly class ProductManagementController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ProductService $products,
        private CommercePresenter $presenter,
        private string $currency,
    ) {
    }

    public function storeProducts(Request $request, string $storeId): JsonResponse
    {
        $page = $this->products->forStore(
            $storeId,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Product $p): array => $this->presenter->product($p));
    }

    public function store(ProductRequest $request, string $storeId): JsonResponse
    {
        $product = $this->products->create(
            $storeId,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            ProductInput::fromArray($request->validated(), $this->currency),
        );

        return $this->data($this->presenter->product($product), 201);
    }

    public function update(ProductRequest $request, string $productId): JsonResponse
    {
        $product = $this->products->update(
            $productId,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            ProductInput::fromArray($request->validated(), $this->currency),
        );

        return $this->data($this->presenter->product($product));
    }

    public function submit(Request $request, string $productId): JsonResponse
    {
        $product = $this->products->submitForApproval($productId, $this->currentUserId($request), $this->actorIsAdmin($request));

        return $this->data($this->presenter->product($product));
    }

    public function describe(Request $request, string $productId): JsonResponse
    {
        $product = $this->products->describe(
            $productId,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $this->currentUserIdOrNull($request),
        );

        return $this->data($this->presenter->product($product));
    }

    public function destroy(Request $request, string $productId): JsonResponse
    {
        $this->products->delete($productId, $this->currentUserId($request), $this->actorIsAdmin($request));

        return new JsonResponse(null, 204);
    }
}
