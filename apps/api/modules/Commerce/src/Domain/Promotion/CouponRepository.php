<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Promotion;

/** Persistence port for the {@see Coupon} aggregate. */
interface CouponRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Coupon;

    public function findByCode(string $code): ?Coupon;

    /**
     * Read the coupon holding an exclusive row lock until the surrounding
     * transaction ends. A usage-limited coupon increments a counter, so the
     * read that authorises redemption must be locked or a limited-run code can
     * be redeemed past its cap by simultaneous checkouts.
     */
    public function findByCodeForUpdate(string $code): ?Coupon;

    public function codeExists(string $code): bool;

    /** @return list<Coupon> */
    public function all(): array;

    public function save(Coupon $coupon): void;
}
