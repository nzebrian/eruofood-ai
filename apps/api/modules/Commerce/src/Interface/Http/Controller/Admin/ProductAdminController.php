<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ProductService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin product moderation: approval queue, approve/reject, feature. */
final readonly class ProductAdminController
{
    use RespondsWithData;

    public function __construct(
        private ProductService $products,
        private CommercePresenter $presenter,
    ) {
    }

    public function queue(Request $request): JsonResponse
    {
        $page = $this->products->approvalQueue((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Product $p): array => $this->presenter->product($p));
    }

    public function approve(string $id): JsonResponse
    {
        return $this->data($this->presenter->product($this->products->publish($id)));
    }

    public function reject(string $id): JsonResponse
    {
        return $this->data($this->presenter->product($this->products->reject($id)));
    }

    public function feature(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['featured' => ['required', 'boolean']]);

        return $this->data($this->presenter->product($this->products->setFeatured($id, (bool) $data['featured'])));
    }
}
