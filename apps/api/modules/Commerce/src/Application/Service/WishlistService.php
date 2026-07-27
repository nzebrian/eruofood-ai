<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Shopping\Wishlist;
use EruoFood\Commerce\Domain\Shopping\WishlistRepository;

/** A user's saved-for-later products. */
final readonly class WishlistService
{
    public function __construct(
        private WishlistRepository $wishlists,
        private ProductRepository $products,
    ) {
    }

    public function get(string $userId): Wishlist
    {
        return $this->wishlists->forUser($userId) ?? Wishlist::forUser($userId);
    }

    public function add(string $userId, string $productId): Wishlist
    {
        $this->products->findById($productId) ?? throw CommerceNotFound::of('product', $productId);
        $wishlist = $this->get($userId);
        $wishlist->add($productId);
        $this->wishlists->save($wishlist);

        return $wishlist;
    }

    public function remove(string $userId, string $productId): Wishlist
    {
        $wishlist = $this->get($userId);
        $wishlist->remove($productId);
        $this->wishlists->save($wishlist);

        return $wishlist;
    }

    /** @return list<Product> the wishlisted products, in saved order */
    public function products(string $userId): array
    {
        $wishlist = $this->get($userId);

        return $this->products->findManyById($wishlist->productIds());
    }
}
