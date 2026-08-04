<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Promotion;

/** Persistence port for the {@see Coupon} aggregate. */
interface CouponRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Coupon;

    public function findByCode(string $code): ?Coupon;

    public function codeExists(string $code): bool;

    /** @return list<Coupon> */
    public function all(): array;

    public function save(Coupon $coupon): void;
}
