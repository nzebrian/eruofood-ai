<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/** How a coupon discounts an order. */
enum CouponType: string
{
    case Percentage = 'percentage';       // percent off the subtotal
    case Fixed = 'fixed';                 // fixed minor-units off
    case FreeShipping = 'free_shipping';  // waives shipping
}
