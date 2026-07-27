<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Commerce\Domain\Enum\ProductStatus;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Product} aggregate. */
interface ProductRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Product;

    public function findBySlug(string $slug): ?Product;

    public function slugExists(string $slug): bool;

    public function findByBarcode(string $barcode): ?Product;

    /**
     * Search the public (published) catalogue.
     *
     * @return Paginated<Product>
     */
    public function search(ProductSearchCriteria $criteria, int $page, int $perPage): Paginated;

    /**
     * A store owner's products (any status).
     *
     * @return Paginated<Product>
     */
    public function forStore(string $storeId, int $page, int $perPage): Paginated;

    /**
     * Products in a given moderation state (admin approval queue).
     *
     * @return Paginated<Product>
     */
    public function withStatus(ProductStatus $status, int $page, int $perPage): Paginated;

    /**
     * Products related to a given product (same category/department) for
     * recommendations & cross-selling.
     *
     * @return list<Product>
     */
    public function related(Product $product, int $limit): array;

    /** @param list<string> $ids
     *  @return list<Product> */
    public function findManyById(array $ids): array;

    public function save(Product $product): void;

    public function delete(string $id): void;
}
