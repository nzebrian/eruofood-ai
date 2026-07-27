<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Shared\Domain\ValueObject\Money;

function coupon(CouponType $type, int $value, int $minSpend = 0, ?int $max = null, ?DateTimeImmutable $expires = null): Coupon
{
    return Coupon::create('c1', 'save', $type, $value, $minSpend, $max, $expires);
}

it('normalises the code to upper-case', function (): void {
    expect(coupon(CouponType::Fixed, 100)->code())->toBe('SAVE');
});

it('computes a percentage discount capped at the subtotal', function (): void {
    $c = coupon(CouponType::Percentage, 10);
    expect($c->discountFor(new Money(500000, 'NGN'))->minorUnits)->toBe(50000);
});

it('computes a fixed discount that never exceeds the subtotal', function (): void {
    $c = coupon(CouponType::Fixed, 800000);
    expect($c->discountFor(new Money(500000, 'NGN'))->minorUnits)->toBe(500000);
});

it('flags free-shipping coupons and grants no subtotal discount', function (): void {
    $c = coupon(CouponType::FreeShipping, 0);
    expect($c->waivesShipping())->toBeTrue()
        ->and($c->discountFor(new Money(500000, 'NGN'))->minorUnits)->toBe(0);
});

it('enforces minimum spend', function (): void {
    $c = coupon(CouponType::Fixed, 50000, minSpend: 1000000);
    expect(fn () => $c->assertRedeemable(new Money(500000, 'NGN'), new DateTimeImmutable()))
        ->toThrow(CommerceInvalidState::class);
});

it('rejects an expired or fully redeemed coupon', function (): void {
    $expired = coupon(CouponType::Fixed, 50000, expires: new DateTimeImmutable('2020-01-01'));
    expect(fn () => $expired->assertRedeemable(new Money(1, 'NGN'), new DateTimeImmutable()))
        ->toThrow(CommerceInvalidState::class);

    $capped = coupon(CouponType::Fixed, 50000, max: 1);
    $capped->redeem();
    expect(fn () => $capped->assertRedeemable(new Money(1, 'NGN'), new DateTimeImmutable()))
        ->toThrow(CommerceInvalidState::class);
});
