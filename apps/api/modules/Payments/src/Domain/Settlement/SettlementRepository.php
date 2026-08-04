<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Settlement} aggregate. */
interface SettlementRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Settlement;

    /** @return Paginated<Settlement> */
    public function forPayee(string $payeeType, string $payeeId, int $page, int $perPage): Paginated;

    /** @return Paginated<Settlement> */
    public function all(int $page, int $perPage): Paginated;

    public function save(Settlement $settlement): void;
}
