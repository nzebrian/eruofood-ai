<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Read;

use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Catalog\CategoryRepository;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria;
use EruoFood\PublicApi\Domain\Read\CommerceReadPort;
use EruoFood\PublicApi\Domain\Read\ProductCategoryResource;
use EruoFood\PublicApi\Domain\Read\ProductResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\Shared\Domain\Paginated;

/**
 * Adapts the Public API's {@see CommerceReadPort} onto the Commerce context. A
 * sanctioned cross-context read seam: it consumes Commerce's published
 * product/category repositories (never their tables or write model) and maps to
 * the Public API's own DTOs. The product search only ever returns the published
 * catalogue (the repository criteria enforce this).
 */
final readonly class CommerceReadAdapter implements CommerceReadPort
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
    ) {
    }

    public function products(ResourceQuery $query): Paginated
    {
        $criteria = new ProductSearchCriteria(
            term: $query->search,
            categoryId: $query->filters['category'] ?? null,
            featuredOnly: ($query->filters['featured'] ?? null) === 'true',
        );
        $page = $this->products->search($criteria, $query->page, $query->perPage);

        return new Paginated(
            array_map(fn (Product $p): ProductResource => $this->toProduct($p), $page->items),
            $page->total,
            $page->page,
            $page->perPage,
        );
    }

    public function product(string $slug): ?ProductResource
    {
        $product = $this->products->findBySlug($slug);

        return $product !== null && $product->isOrderable() ? $this->toProduct($product) : null;
    }

    public function categories(): array
    {
        return array_map(
            fn (Category $c): ProductCategoryResource => $this->toCategory($c),
            $this->categories->all(),
        );
    }

    private function toProduct(Product $p): ProductResource
    {
        $price = $p->basePrice();

        return new ProductResource(
            $p->id(),
            (string) $p->slug(),
            $p->name(),
            $p->kind()->value,
            $p->department()?->value,
            $p->description(),
            $price->minorUnits,
            $price->currency,
            $p->categoryId(),
            array_values($p->images()),
        );
    }

    private function toCategory(Category $c): ProductCategoryResource
    {
        return new ProductCategoryResource(
            $c->id(),
            (string) $c->slug(),
            $c->name(),
            $c->kind()->value,
            $c->parentId(),
            $c->sortOrder(),
        );
    }
}
