<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

/** Persistence port for {@see Supplier}. */
interface SupplierRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Supplier;

    /** @return list<Supplier> */
    public function all(): array;

    public function save(Supplier $supplier): void;
}
