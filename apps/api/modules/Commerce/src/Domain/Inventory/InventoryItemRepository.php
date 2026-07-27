<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see InventoryItem} aggregate. */
interface InventoryItemRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?InventoryItem;

    public function findForProduct(string $productId, ?string $variantSku): ?InventoryItem;

    /** @return list<InventoryItem> */
    public function forProduct(string $productId): array;

    /**
     * Stock records at or below their low-stock threshold (alerting dashboard).
     *
     * @return Paginated<InventoryItem>
     */
    public function lowStock(int $page, int $perPage): Paginated;

    public function save(InventoryItem $item): void;
}
