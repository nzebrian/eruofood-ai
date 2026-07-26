<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Cart\Cart;
use EruoFood\Marketplace\Domain\Cart\CartItem;
use EruoFood\Marketplace\Domain\Cart\CartRepository;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;

/**
 * The shopping cart: resolves menu items to captured names/prices and keeps the
 * user's single-vendor cart consistent.
 */
final readonly class CartService
{
    public function __construct(
        private CartRepository $carts,
        private MenuItemRepository $items,
        private string $currency,
    ) {
    }

    public function get(string $userId): Cart
    {
        return $this->carts->forUser($userId) ?? Cart::forUser($userId, $this->currency);
    }

    public function addItem(string $userId, string $menuItemId, ?string $variantName, int $quantity): Cart
    {
        $item = $this->items->findById($menuItemId) ?? throw MarketplaceNotFound::of('menu item', $menuItemId);
        if (! $item->isOrderable()) {
            throw new MarketplaceInvalidState(sprintf('"%s" is currently unavailable.', $item->name()));
        }

        $cart = $this->get($userId);
        $cart->add($item->vendorId(), new CartItem(
            menuItemId: $item->id(),
            name: $item->name(),
            variantName: $variantName,
            unitPrice: $item->priceFor($variantName),
            quantity: max(1, $quantity),
        ));
        $this->carts->save($cart);

        return $cart;
    }

    public function setQuantity(string $userId, string $menuItemId, ?string $variantName, int $quantity): Cart
    {
        $cart = $this->get($userId);
        $cart->setQuantity($menuItemId, $variantName, $quantity);
        $this->carts->save($cart);

        return $cart;
    }

    public function removeItem(string $userId, string $menuItemId, ?string $variantName): Cart
    {
        $cart = $this->get($userId);
        $cart->remove($menuItemId, $variantName);
        $this->carts->save($cart);

        return $cart;
    }

    public function clear(string $userId): void
    {
        $this->carts->clear($userId);
    }
}
