<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Application\Input\ProductInput;
use EruoFood\Commerce\Application\Port\CommerceAdvisor;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria;
use EruoFood\Commerce\Domain\Enum\ProductStatus;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * Product lifecycle for sellers (create/update/delete, submit for approval) and
 * the public/admin catalogue reads. Publishing is gated on the owning store
 * being able to trade and — when verification is required — on admin approval.
 */
final readonly class ProductService
{
    public function __construct(
        private ProductRepository $products,
        private StoreRepository $stores,
        private CommerceAdvisor $advisor,
        private EventBus $events,
        private bool $requireVerification,
    ) {
    }

    public function create(string $storeId, string $actorUserId, bool $actorIsAdmin, ProductInput $input): Product
    {
        $store = $this->ownedStore($storeId, $actorUserId, $actorIsAdmin);
        if (! $store->canTrade()) {
            throw new CommerceInvalidState('This store is not yet verified and cannot publish products.');
        }

        $product = Product::create(
            id: $this->products->nextIdentity(),
            storeId: $storeId,
            categoryId: $input->categoryId,
            name: $input->name,
            slug: $this->uniqueSlug($input->name),
            kind: $input->kind,
            department: $input->department,
            description: $input->description,
            basePrice: $input->basePrice,
            variants: $input->variants,
            images: $input->images,
            tags: $input->tags,
            barcode: $input->barcode,
            brand: $input->brand,
            autoPublish: ! $this->requireVerification,
        );
        $this->persist($product);

        return $product;
    }

    public function update(string $productId, string $actorUserId, bool $actorIsAdmin, ProductInput $input): Product
    {
        $product = $this->ownedProduct($productId, $actorUserId, $actorIsAdmin);
        $product->update(
            $input->categoryId,
            $input->name,
            $input->description,
            $input->basePrice,
            $input->variants,
            $input->images,
            $input->tags,
            $input->barcode,
            $input->brand,
        );
        $this->persist($product);

        return $product;
    }

    public function submitForApproval(string $productId, string $actorUserId, bool $actorIsAdmin): Product
    {
        $product = $this->ownedProduct($productId, $actorUserId, $actorIsAdmin);
        $product->submitForApproval();
        $this->persist($product);

        return $product;
    }

    public function publish(string $productId): Product
    {
        $product = $this->getById($productId);
        $product->publish();
        $this->persist($product);

        return $product;
    }

    public function reject(string $productId): Product
    {
        $product = $this->getById($productId);
        $product->reject();
        $this->persist($product);

        return $product;
    }

    public function setFeatured(string $productId, bool $featured): Product
    {
        $product = $this->getById($productId);
        $product->setFeatured($featured);
        $this->persist($product);

        return $product;
    }

    public function describe(string $productId, string $actorUserId, bool $actorIsAdmin, ?string $requesterId): Product
    {
        $product = $this->ownedProduct($productId, $actorUserId, $actorIsAdmin);
        $store = $this->stores->findById($product->storeId());
        $blurb = $this->advisor->recommendationBlurb(
            sprintf('a product description for "%s" sold by "%s"', $product->name(), $store?->name() ?? 'a store'),
            array_merge([$product->name()], $product->tags()),
            $requesterId,
        );
        $product->setAiDescription($blurb);
        $this->persist($product);

        return $product;
    }

    public function delete(string $productId, string $actorUserId, bool $actorIsAdmin): void
    {
        $product = $this->ownedProduct($productId, $actorUserId, $actorIsAdmin);
        $this->products->delete($product->id());
    }

    public function getById(string $productId): Product
    {
        return $this->products->findById($productId) ?? throw CommerceNotFound::of('product', $productId);
    }

    public function getBySlug(string $slug): Product
    {
        return $this->products->findBySlug($slug) ?? throw CommerceNotFound::of('product', $slug);
    }

    public function getByBarcode(string $barcode): Product
    {
        return $this->products->findByBarcode($barcode) ?? throw CommerceNotFound::of('product', $barcode);
    }

    /** @return Paginated<Product> */
    public function search(ProductSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        return $this->products->search($criteria, $page, $perPage);
    }

    /** @return Paginated<Product> */
    public function forStore(string $storeId, string $actorUserId, bool $actorIsAdmin, int $page, int $perPage): Paginated
    {
        $this->ownedStore($storeId, $actorUserId, $actorIsAdmin);

        return $this->products->forStore($storeId, $page, $perPage);
    }

    /** @return Paginated<Product> */
    public function approvalQueue(int $page, int $perPage): Paginated
    {
        return $this->products->withStatus(ProductStatus::Pending, $page, $perPage);
    }

    /** @return list<Product> */
    public function related(Product $product, int $limit): array
    {
        return $this->products->related($product, $limit);
    }

    private function persist(Product $product): void
    {
        $this->products->save($product);
        foreach ($product->releaseEvents() as $event) {
            $this->events->publish($event);
        }
    }

    private function ownedProduct(string $productId, string $actorUserId, bool $actorIsAdmin): Product
    {
        $product = $this->getById($productId);
        if ($actorIsAdmin) {
            return $product;
        }
        $store = $this->stores->findById($product->storeId());
        if ($store === null || ! $store->isOwnedBy($actorUserId)) {
            throw new \EruoFood\Commerce\Domain\Exception\NotResourceOwner();
        }

        return $product;
    }

    private function ownedStore(string $storeId, string $actorUserId, bool $actorIsAdmin): void
    {
        $store = $this->stores->findById($storeId) ?? throw CommerceNotFound::of('store', $storeId);
        if (! $actorIsAdmin && ! $store->isOwnedBy($actorUserId)) {
            throw new \EruoFood\Commerce\Domain\Exception\NotResourceOwner();
        }
    }

    private function uniqueSlug(string $name): Slug
    {
        $base = Slug::fromTitle($name);
        if (! $this->products->slugExists($base->value)) {
            return $base;
        }
        for ($i = 2; $i <= 100; $i++) {
            $candidate = new Slug($base->value.'-'.$i);
            if (! $this->products->slugExists($candidate->value)) {
                return $candidate;
            }
        }
        throw new CommerceConflict('Could not generate a unique product slug.');
    }
}
