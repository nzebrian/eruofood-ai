<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

/** Persistence port for {@see Warehouse}. */
interface WarehouseRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Warehouse;

    /** @return list<Warehouse> */
    public function all(): array;

    public function save(Warehouse $warehouse): void;
}
