<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Pricing;

use DateTimeImmutable;
use EruoFood\Commerce\Application\DTO\DiscountResult;
use EruoFood\Commerce\Application\Port\DiscountEngine;
use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;

/**
 * Resolves the order-level discount for a cart from its applied coupon code.
 * Product-level promotions are already reflected in each line's captured price
 * (via the pricing strategy), so this engine is concerned only with the coupon.
 * An invalid coupon surfaces as a domain error at checkout.
 */
final readonly class CouponDiscountEngine implements DiscountEngine
{
    public function __construct(private CouponRepository $coupons)
    {
    }

    public function evaluate(Cart $cart): DiscountResult
    {
        $code = $cart->couponCode();
        if ($code === null) {
            return DiscountResult::none($cart->currency());
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null) {
            return DiscountResult::none($cart->currency());
        }

        $subtotal = $cart->subtotal();
        $coupon->assertRedeemable($subtotal, new DateTimeImmutable());

        return new DiscountResult(
            amount: $coupon->discountFor($subtotal),
            freeShipping: $coupon->waivesShipping(),
            coupon: $coupon,
        );
    }
}
