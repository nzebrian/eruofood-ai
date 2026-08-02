<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Reward;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Reward} catalogue. */
interface RewardRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Reward;

    /**
     * The catalogue. When `$activeOnly` is true, only currently-redeemable
     * rewards are returned (for the customer storefront).
     *
     * @return Paginated<Reward>
     */
    public function catalogue(bool $activeOnly, int $page, int $perPage): Paginated;

    public function save(Reward $reward): void;
}
