<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartItem;
use EruoFood\Shared\Domain\ValueObject\Money;

function cartItem(string $productId, string $storeId, ?string $sku, int $minor, int $qty): CartItem
{
    return new CartItem($productId, $storeId, 'Item '.$productId, $sku, new Money($minor, 'NGN'), $qty);
}

it('merges the same product+variant and holds items from multiple stores', function (): void {
    $cart = Cart::forUser('u1', 'NGN');
    $cart->add(cartItem('p1', 's1', null, 100000, 1));
    $cart->add(cartItem('p1', 's1', null, 100000, 2)); // merges → qty 3
    $cart->add(cartItem('p2', 's2', 'L', 250000, 1));  // different store, allowed

    expect($cart->items())->toHaveCount(2)
        ->and($cart->itemCount())->toBe(4)
        ->and($cart->subtotal()->minorUnits)->toBe(3 * 100000 + 250000);
});

it('updates quantity, removes lines and applies a coupon', function (): void {
    $cart = Cart::forUser('u1', 'NGN');
    $cart->add(cartItem('p1', 's1', null, 100000, 3));
    $cart->setQuantity('p1', null, 1);
    expect($cart->subtotal()->minorUnits)->toBe(100000);

    $cart->applyCoupon('welcome10');
    expect($cart->couponCode())->toBe('WELCOME10');

    $cart->setQuantity('p1', null, 0); // removes it
    expect($cart->isEmpty())->toBeTrue();
});
