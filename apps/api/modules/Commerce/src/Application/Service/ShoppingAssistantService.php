<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Application\Port\CommerceAdvisor;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria;

/**
 * AI-powered shopping intelligence: product recommendations, cross-sell &
 * up-sell suggestions, and the free-text smart shopping assistant. All AI text
 * flows through the {@see CommerceAdvisor} port (which bridges to the AI
 * module's published contract).
 *
 * @phpstan-type Suggestion array{products: list<Product>, blurb: string}
 */
final readonly class ShoppingAssistantService
{
    public function __construct(
        private ProductRepository $products,
        private CommerceAdvisor $advisor,
    ) {
    }

    /**
     * Recommend featured/popular products for a shopper.
     *
     * @return array{products: list<Product>, blurb: string}
     */
    public function recommendations(?string $userId, int $limit = 6): array
    {
        $page = $this->products->search(
            new ProductSearchCriteria(featuredOnly: true, sort: 'rating'),
            1,
            $limit,
        );
        $products = $page->items;
        if ($products === []) {
            $products = $this->products->search(new ProductSearchCriteria(sort: 'newest'), 1, $limit)->items;
        }

        return [
            'products' => $products,
            'blurb' => $this->blurb('picks we think you will love', $products, $userId),
        ];
    }

    /**
     * Cross-sell: products that pair well with the given product.
     *
     * @return array{products: list<Product>, blurb: string}
     */
    public function crossSell(string $productId, ?string $userId, int $limit = 4): array
    {
        $product = $this->products->findById($productId);
        $related = $product !== null ? $this->products->related($product, $limit) : [];

        return [
            'products' => $related,
            'blurb' => $this->blurb(
                $product !== null ? sprintf('goes well with %s', $product->name()) : 'you might also like',
                $related,
                $userId,
            ),
        ];
    }

    /**
     * Up-sell: higher-value alternatives to the given product (same category,
     * priced above it).
     *
     * @return array{products: list<Product>, blurb: string}
     */
    public function upSell(string $productId, ?string $userId, int $limit = 4): array
    {
        $product = $this->products->findById($productId);
        $candidates = $product !== null ? $this->products->related($product, $limit * 3) : [];
        $upsells = [];
        if ($product !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->basePrice()->minorUnits > $product->basePrice()->minorUnits) {
                    $upsells[] = $candidate;
                }
                if (count($upsells) >= $limit) {
                    break;
                }
            }
        }

        return [
            'products' => $upsells,
            'blurb' => $this->blurb('upgrade picks', $upsells, $userId),
        ];
    }

    /** Answer a free-text shopping question. */
    public function assist(string $question, ?string $userId): string
    {
        return $this->advisor->assist($question, $userId);
    }

    /**
     * @param list<Product> $products
     */
    private function blurb(string $context, array $products, ?string $userId): string
    {
        if ($products === []) {
            return '';
        }
        $names = array_map(static fn (Product $p): string => $p->name(), $products);

        return $this->advisor->recommendationBlurb($context, $names, $userId);
    }
}
