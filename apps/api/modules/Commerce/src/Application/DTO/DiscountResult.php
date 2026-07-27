<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\DTO;

use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Shared\Domain\ValueObject\Money;

/** The outcome of evaluating discounts for a cart at checkout. */
final readonly class DiscountResult
{
    public function __construct(
        public Money $amount,
        public bool $freeShipping,
        public ?Coupon $coupon,
    ) {
    }

    public static function none(string $currency): self
    {
        return new self(new Money(0, $currency), false, null);
    }
}
