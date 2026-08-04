<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Reward;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see Redemption}s (issued vouchers). */
interface RedemptionRepository
{
    public function nextIdentity(): string;

    /** A unique, human-readable redemption code (retryable on collision). */
    public function nextCode(): string;

    public function findById(string $id): ?Redemption;

    public function findByCode(string $code): ?Redemption;

    /**
     * @return Paginated<Redemption>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated;

    public function save(Redemption $redemption): void;
}
