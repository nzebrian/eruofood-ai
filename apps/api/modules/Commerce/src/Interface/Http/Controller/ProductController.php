<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ProductReviewService;
use EruoFood\Commerce\Application\Service\ProductService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductReview;
use EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public product discovery: search, detail, barcode lookup and reviews. */
final readonly class ProductController
{
    use RespondsWithData;

    public function __construct(
        private ProductService $products,
        private ProductReviewService $reviews,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $criteria = new ProductSearchCriteria(
            term: ((string) $request->string('q')) ?: null,
            storeId: ((string) $request->string('store_id')) ?: null,
            categoryId: ((string) $request->string('category_id')) ?: null,
            kind: $request->filled('kind') ? ProductKind::tryFrom((string) $request->string('kind')) : null,
            department: $request->filled('department') ? GroceryDepartment::tryFrom((string) $request->string('department')) : null,
            minPriceMinor: $request->filled('min_price') ? (int) $request->integer('min_price') : null,
            maxPriceMinor: $request->filled('max_price') ? (int) $request->integer('max_price') : null,
            featuredOnly: $request->boolean('featured'),
            sort: (string) (((string) $request->string('sort')) ?: 'relevance'),
        );

        $page = $this->products->search($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Product $p): array => $this->presenter->productSummary($p));
    }

    public function show(string $slug): JsonResponse
    {
        $product = $this->products->getBySlug($slug);
        $related = $this->products->related($product, 6);

        return $this->data(array_merge($this->presenter->product($product), [
            'related' => array_map(fn (Product $p): array => $this->presenter->productSummary($p), $related),
        ]));
    }

    public function byBarcode(string $barcode): JsonResponse
    {
        return $this->data($this->presenter->product($this->products->getByBarcode($barcode)));
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $page = $this->reviews->list($id, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (ProductReview $r): array => $this->presenter->review($r));
    }
}
