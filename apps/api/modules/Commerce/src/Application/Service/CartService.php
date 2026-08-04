<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Application\Port\PricingStrategy;
use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartItem;
use EruoFood\Commerce\Domain\Cart\CartRepository;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;

/**
 * The shopping cart: resolves products to captured names/prices (via the
 * pricing strategy) and keeps the user's multi-store cart consistent.
 */
final readonly class CartService
{
    public function __construct(
        private CartRepository $carts,
        private ProductRepository $products,
        private PricingStrategy $pricing,
        private string $currency,
    ) {
    }

    public function get(string $userId): Cart
    {
        return $this->carts->forUser($userId) ?? Cart::forUser($userId, $this->currency);
    }

    public function addItem(string $userId, string $productId, ?string $variantSku, int $quantity): Cart
    {
        $product = $this->products->findById($productId) ?? throw CommerceNotFound::of('product', $productId);
        if (! $product->isOrderable()) {
            throw new CommerceInvalidState(sprintf('"%s" is not available for purchase.', $product->name()));
        }
        if ($variantSku !== null && $product->variant($variantSku) === null) {
            throw new CommerceInvalidState(sprintf('Unknown variant "%s".', $variantSku));
        }

        $cart = $this->get($userId);
        $cart->add(new CartItem(
            productId: $product->id(),
            storeId: $product->storeId(),
            name: $product->name(),
            variantSku: $variantSku,
            unitPrice: $this->pricing->priceFor($product, $variantSku),
            quantity: max(1, $quantity),
        ));
        $this->carts->save($cart);

        return $cart;
    }

    public function setQuantity(string $userId, string $productId, ?string $variantSku, int $quantity): Cart
    {
        $cart = $this->get($userId);
        $cart->setQuantity($productId, $variantSku, $quantity);
        $this->carts->save($cart);

        return $cart;
    }

    public function removeItem(string $userId, string $productId, ?string $variantSku): Cart
    {
        $cart = $this->get($userId);
        $cart->remove($productId, $variantSku);
        $this->carts->save($cart);

        return $cart;
    }

    public function applyCoupon(string $userId, ?string $code): Cart
    {
        $cart = $this->get($userId);
        $cart->applyCoupon($code);
        $this->carts->save($cart);

        return $cart;
    }

    public function clear(string $userId): void
    {
        $this->carts->clear($userId);
    }
}
