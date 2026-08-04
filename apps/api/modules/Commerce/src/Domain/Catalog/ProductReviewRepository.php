<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see ProductReview}. */
interface ProductReviewRepository
{
    public function nextIdentity(): string;

    public function existsFor(string $productId, string $userId): bool;

    /** @return Paginated<ProductReview> */
    public function forProduct(string $productId, int $page, int $perPage): Paginated;

    /** @return array{average: float, count: int} */
    public function summaryFor(string $productId): array;

    public function save(ProductReview $review): void;
}
