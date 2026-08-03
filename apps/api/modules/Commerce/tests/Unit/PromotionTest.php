<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Enum\PromotionType;
use EruoFood\Commerce\Domain\Promotion\Promotion;
use EruoFood\Shared\Domain\ValueObject\Money;

it('applies only within its active window', function (): void {
    $promo = Promotion::create(
        'pr1',
        null,
        'August Sale',
        PromotionType::Percentage,
        20,
        ['p1'],
        new DateTimeImmutable('2026-08-01'),
        new DateTimeImmutable('2026-08-31'),
        false,
    );
    expect($promo->isActiveAt(new DateTimeImmutable('2026-08-15')))->toBeTrue()
        ->and($promo->isActiveAt(new DateTimeImmutable('2026-09-15')))->toBeFalse()
        ->and($promo->appliesTo('p1'))->toBeTrue()
        ->and($promo->appliesTo('p2'))->toBeFalse();
});

it('discounts a price and floors at zero', function (): void {
    $percent = Promotion::create('pr2', null, 'Half', PromotionType::Percentage, 50, ['p1'], null, null, true);
    expect($percent->applyTo(new Money(1000000, 'NGN'))->minorUnits)->toBe(500000)
        ->and($percent->isFlashSale())->toBeTrue();

    $fixed = Promotion::create('pr3', null, 'Big', PromotionType::Fixed, 9999999, ['p1'], null, null, false);
    expect($fixed->applyTo(new Money(1000000, 'NGN'))->minorUnits)->toBe(0);
});
