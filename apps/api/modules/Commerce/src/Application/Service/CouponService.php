<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;

/** Coupon codes management (admin-curated). */
final readonly class CouponService
{
    public function __construct(private CouponRepository $coupons)
    {
    }

    public function create(
        string $code,
        CouponType $type,
        int $value,
        int $minSpendMinor,
        ?int $maxRedemptions,
        ?DateTimeImmutable $expiresAt,
    ): Coupon {
        $normalised = strtoupper(trim($code));
        if ($this->coupons->codeExists($normalised)) {
            throw new CommerceConflict(sprintf('Coupon code "%s" already exists.', $normalised));
        }

        $coupon = Coupon::create(
            $this->coupons->nextIdentity(),
            $normalised,
            $type,
            $value,
            $minSpendMinor,
            $maxRedemptions,
            $expiresAt,
        );
        $this->coupons->save($coupon);

        return $coupon;
    }

    /** @return list<Coupon> */
    public function list(): array
    {
        return $this->coupons->all();
    }

    public function deactivate(string $id): Coupon
    {
        $coupon = $this->coupons->findById($id) ?? throw CommerceNotFound::of('coupon', $id);
        $coupon->deactivate();
        $this->coupons->save($coupon);

        return $coupon;
    }
}
