<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Commerce\Domain\Inventory\Supplier;
use EruoFood\Commerce\Domain\Inventory\SupplierRepository;
use EruoFood\Commerce\Domain\Inventory\Warehouse;
use EruoFood\Commerce\Domain\Inventory\WarehouseRepository;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Domain\ValueObject\Batch;
use EruoFood\Shared\Domain\Paginated;

/**
 * Stock, warehouse and supplier management. Stock is keyed by (product, variant
 * SKU): receiving tops up on-hand quantity (optionally as an expiry-tracked
 * batch), and the low-stock query drives alerting dashboards.
 */
final readonly class InventoryService
{
    public function __construct(
        private InventoryItemRepository $items,
        private WarehouseRepository $warehouses,
        private SupplierRepository $suppliers,
        private int $defaultLowStockThreshold,
    ) {
    }

    public function receive(
        string $productId,
        ?string $variantSku,
        int $quantity,
        ?string $warehouseId,
        ?string $supplierId,
        ?string $batchNumber,
        ?DateTimeImmutable $expiresAt,
        ?int $lowStockThreshold,
    ): InventoryItem {
        $item = $this->items->findForProduct($productId, $variantSku);
        if ($item === null) {
            $item = InventoryItem::open(
                $this->items->nextIdentity(),
                $productId,
                $variantSku,
                $warehouseId,
                $supplierId,
                0,
                $lowStockThreshold ?? $this->defaultLowStockThreshold,
            );
        }
        if ($lowStockThreshold !== null) {
            $item->setLowStockThreshold($lowStockThreshold);
        }
        if ($supplierId !== null) {
            $item->assignSupplier($supplierId);
        }

        $batch = $batchNumber !== null
            ? new Batch($batchNumber, $quantity, $expiresAt, new DateTimeImmutable())
            : null;
        $item->receive($quantity, $batch);
        $this->items->save($item);

        return $item;
    }

    public function adjust(string $inventoryId, int $delta): InventoryItem
    {
        $item = $this->items->findById($inventoryId) ?? throw CommerceNotFound::of('inventory item', $inventoryId);
        if ($delta >= 0) {
            $item->receive(max(1, $delta));
        } else {
            $item->deduct(abs($delta));
        }
        $this->items->save($item);

        return $item;
    }

    /** @return list<InventoryItem> */
    public function forProduct(string $productId): array
    {
        return $this->items->forProduct($productId);
    }

    /** @return Paginated<InventoryItem> */
    public function lowStock(int $page, int $perPage): Paginated
    {
        return $this->items->lowStock($page, $perPage);
    }

    public function createWarehouse(string $name, ?string $code, ?Address $address): Warehouse
    {
        $warehouse = Warehouse::create($this->warehouses->nextIdentity(), $name, $code, $address);
        $this->warehouses->save($warehouse);

        return $warehouse;
    }

    /** @return list<Warehouse> */
    public function warehouses(): array
    {
        return $this->warehouses->all();
    }

    public function createSupplier(string $name, ?string $contactName, ?string $email, ?string $phone): Supplier
    {
        $supplier = Supplier::create($this->suppliers->nextIdentity(), $name, $contactName, $email, $phone);
        $this->suppliers->save($supplier);

        return $supplier;
    }

    /** @return list<Supplier> */
    public function suppliers(): array
    {
        return $this->suppliers->all();
    }
}
