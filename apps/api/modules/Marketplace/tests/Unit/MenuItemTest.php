<?php

declare(strict_types=1);

use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\ValueObject\MenuVariant;
use EruoFood\Marketplace\Domain\ValueObject\Promotion;
use EruoFood\Shared\Domain\ValueObject\Money;

function menuItem(): MenuItem
{
    return MenuItem::create(
        id: 'm1',
        vendorId: 'v1',
        categoryId: null,
        name: 'Jollof Rice',
        description: null,
        basePrice: new Money(250000, 'NGN'),
        variants: [new MenuVariant('Large', new Money(80000, 'NGN'))],
        trackInventory: true,
        stock: 5,
    );
}

it('prices base, variant delta and promotion together', function (): void {
    $item = menuItem();
    expect($item->priceFor(null)->minorUnits)->toBe(250000)
        ->and($item->priceFor('Large')->minorUnits)->toBe(330000);

    $item->setPromotion(new Promotion(Promotion::TYPE_PERCENTAGE, 10));
    expect($item->priceFor(null)->minorUnits)->toBe(225000)      // 10% off 250000
        ->and($item->priceFor('Large')->minorUnits)->toBe(297000); // 10% off 330000
});

it('rejects an unknown variant', function (): void {
    expect(fn () => menuItem()->priceFor('Small'))->toThrow(MarketplaceInvalidState::class);
});

it('tracks stock and orderability', function (): void {
    $item = menuItem();
    expect($item->isOrderable())->toBeTrue();

    $item->reduceStock(5);
    expect($item->stock())->toBe(0)->and($item->isOrderable())->toBeFalse();

    expect(fn () => $item->reduceStock(1))->toThrow(MarketplaceInvalidState::class);
});

it('is not orderable when unavailable', function (): void {
    $item = menuItem();
    $item->setAvailability(false);
    expect($item->isOrderable())->toBeFalse();
});
