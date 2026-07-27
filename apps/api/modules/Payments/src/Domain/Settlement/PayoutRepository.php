<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Payout} aggregate. */
interface PayoutRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Payout;

    /** @return Paginated<Payout> */
    public function all(int $page, int $perPage): Paginated;

    public function save(Payout $payout): void;
}
