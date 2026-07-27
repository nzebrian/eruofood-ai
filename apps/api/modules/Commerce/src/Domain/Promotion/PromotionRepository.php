<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Promotion;

/** Persistence port for the {@see Promotion} aggregate. */
interface PromotionRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Promotion;

    /** @return list<Promotion> all promotions active at the given moment */
    public function activeAt(\DateTimeImmutable $now): array;

    /** @return list<Promotion> active flash sales */
    public function activeFlashSales(\DateTimeImmutable $now): array;

    /** @return list<Promotion> */
    public function forStore(string $storeId): array;

    public function save(Promotion $promotion): void;

    public function delete(string $id): void;
}
