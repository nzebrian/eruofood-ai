<?php

declare(strict_types=1);

use EruoFood\Marketplace\Domain\Cart\Cart;
use EruoFood\Marketplace\Domain\Cart\CartItem;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

function item(string $id, int $price, int $qty, ?string $variant = null): CartItem
{
    return new CartItem($id, 'Item '.$id, $variant, new Money($price, 'NGN'), $qty);
}

it('merges the same item+variant and sums the subtotal', function (): void {
    $cart = Cart::forUser('u1', 'NGN');
    $cart->add('v1', item('i1', 100000, 1));
    $cart->add('v1', item('i1', 100000, 2)); // merges to qty 3
    $cart->add('v1', item('i2', 50000, 1));

    expect($cart->items())->toHaveCount(2)
        ->and($cart->subtotal()->minorUnits)->toBe(100000 * 3 + 50000)
        ->and($cart->vendorId())->toBe('v1');
});

it('refuses items from a second vendor until cleared', function (): void {
    $cart = Cart::forUser('u1', 'NGN');
    $cart->add('v1', item('i1', 100000, 1));

    expect(fn () => $cart->add('v2', item('i9', 100000, 1)))->toThrow(MarketplaceInvalidState::class);

    $cart->clear();
    $cart->add('v2', item('i9', 100000, 1));
    expect($cart->vendorId())->toBe('v2');
});

it('updates quantities and drops the vendor when emptied', function (): void {
    $cart = Cart::forUser('u1', 'NGN');
    $cart->add('v1', item('i1', 100000, 2));
    $cart->setQuantity('i1', null, 5);
    expect($cart->items()[0]->quantity)->toBe(5);

    $cart->remove('i1', null);
    expect($cart->isEmpty())->toBeTrue()->and($cart->vendorId())->toBeNull();
});
