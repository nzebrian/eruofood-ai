<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductReview;
use EruoFood\Commerce\Domain\Catalog\ProductReviewRepository;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Shared\Domain\Paginated;

/** Product ratings & reviews (one per user per product), with summary rollup. */
final readonly class ProductReviewService
{
    public function __construct(
        private ProductReviewRepository $reviews,
        private ProductRepository $products,
    ) {
    }

    public function add(string $productId, string $userId, int $rating, ?string $comment): ProductReview
    {
        $product = $this->products->findById($productId) ?? throw CommerceNotFound::of('product', $productId);
        if ($this->reviews->existsFor($productId, $userId)) {
            throw new CommerceConflict('You have already reviewed this product.');
        }

        $review = ProductReview::create(
            $this->reviews->nextIdentity(),
            $productId,
            $userId,
            $rating,
            $comment,
            new DateTimeImmutable(),
        );
        $this->reviews->save($review);

        $summary = $this->reviews->summaryFor($productId);
        $product->applyRatingSummary($summary['average'], $summary['count']);
        $this->products->save($product);

        return $review;
    }

    /** @return Paginated<ProductReview> */
    public function list(string $productId, int $page, int $perPage): Paginated
    {
        return $this->reviews->forProduct($productId, $page, $perPage);
    }
}
