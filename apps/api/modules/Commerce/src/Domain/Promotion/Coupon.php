<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Promotion;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A redeemable discount code applied to a whole order at checkout. Enforces its
 * own eligibility rules (minimum spend, expiry, redemption cap) and computes the
 * discount it grants against a subtotal.
 */
final class Coupon
{
    private function __construct(
        private readonly string $id,
        private readonly string $code,
        private CouponType $type,
        private int $value,
        private int $minSpendMinor,
        private ?int $maxRedemptions,
        private int $timesRedeemed,
        private ?DateTimeImmutable $expiresAt,
        private bool $active,
    ) {
        if ($type === CouponType::Percentage && ($value < 1 || $value > 100)) {
            throw new InvalidArgumentException('Percentage coupon must be 1-100.');
        }
        if ($value < 0) {
            throw new InvalidArgumentException('Coupon value cannot be negative.');
        }
    }

    public static function create(
        string $id,
        string $code,
        CouponType $type,
        int $value,
        int $minSpendMinor = 0,
        ?int $maxRedemptions = null,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            $id, strtoupper(trim($code)), $type, $value, max(0, $minSpendMinor),
            $maxRedemptions, 0, $expiresAt, true,
        );
    }

    public static function reconstitute(
        string $id,
        string $code,
        CouponType $type,
        int $value,
        int $minSpendMinor,
        ?int $maxRedemptions,
        int $timesRedeemed,
        ?DateTimeImmutable $expiresAt,
        bool $active,
    ): self {
        return new self(
            $id, $code, $type, $value, $minSpendMinor, $maxRedemptions,
            $timesRedeemed, $expiresAt, $active,
        );
    }

    /** Assert the coupon can be applied to a subtotal now, or throw. */
    public function assertRedeemable(Money $subtotal, DateTimeImmutable $now): void
    {
        if (! $this->active) {
            throw new CommerceInvalidState(sprintf('Coupon "%s" is not active.', $this->code));
        }
        if ($this->expiresAt !== null && $now > $this->expiresAt) {
            throw new CommerceInvalidState(sprintf('Coupon "%s" has expired.', $this->code));
        }
        if ($this->maxRedemptions !== null && $this->timesRedeemed >= $this->maxRedemptions) {
            throw new CommerceInvalidState(sprintf('Coupon "%s" has been fully redeemed.', $this->code));
        }
        if ($subtotal->minorUnits < $this->minSpendMinor) {
            throw new CommerceInvalidState(sprintf(
                'A minimum spend of %d is required for coupon "%s".',
                $this->minSpendMinor,
                $this->code,
            ));
        }
    }

    /** The discount this coupon grants against a subtotal (never below zero). */
    public function discountFor(Money $subtotal): Money
    {
        $off = match ($this->type) {
            CouponType::Percentage => (int) round($subtotal->minorUnits * $this->value / 100),
            CouponType::Fixed => $this->value,
            CouponType::FreeShipping => 0, // applied to shipping, not the subtotal
        };

        return new Money(min($subtotal->minorUnits, max(0, $off)), $subtotal->currency);
    }

    public function waivesShipping(): bool
    {
        return $this->type === CouponType::FreeShipping;
    }

    public function redeem(): void
    {
        $this->timesRedeemed++;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function type(): CouponType
    {
        return $this->type;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function minSpendMinor(): int
    {
        return $this->minSpendMinor;
    }

    public function maxRedemptions(): ?int
    {
        return $this->maxRedemptions;
    }

    public function timesRedeemed(): int
    {
        return $this->timesRedeemed;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
