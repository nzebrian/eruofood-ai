<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Commerce\Domain\ValueObject\Batch;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\InventoryItemModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentInventoryItemRepository implements InventoryItemRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?InventoryItem
    {
        $m = InventoryItemModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findForProduct(string $productId, ?string $variantSku): ?InventoryItem
    {
        $query = InventoryItemModel::query()->where('product_id', $productId);
        $variantSku === null ? $query->whereNull('variant_sku') : $query->where('variant_sku', $variantSku);
        $m = $query->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forProduct(string $productId): array
    {
        return array_map(
            fn (InventoryItemModel $m): InventoryItem => $this->toDomain($m),
            InventoryItemModel::query()->where('product_id', $productId)->get()->all(),
        );
    }

    public function lowStock(int $page, int $perPage): Paginated
    {
        $paginator = InventoryItemModel::query()
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->orderBy('quantity')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (InventoryItemModel $m): InventoryItem => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(InventoryItem $item): void
    {
        $model = InventoryItemModel::query()->find($item->id()) ?? new InventoryItemModel();
        $model->id = $item->id();
        $model->product_id = $item->productId();
        $model->variant_sku = $item->variantSku();
        $model->warehouse_id = $item->warehouseId();
        $model->supplier_id = $item->supplierId();
        $model->quantity = $item->quantity();
        $model->low_stock_threshold = $item->lowStockThreshold();
        $model->batches = array_map(static fn (Batch $b): array => $b->toArray(), $item->batches());
        $model->save();
    }

    private function toDomain(InventoryItemModel $m): InventoryItem
    {
        return InventoryItem::reconstitute(
            id: $m->id,
            productId: $m->product_id,
            variantSku: $m->variant_sku,
            warehouseId: $m->warehouse_id,
            supplierId: $m->supplier_id,
            quantity: $m->quantity,
            lowStockThreshold: $m->low_stock_threshold,
            batches: array_map(static fn (array $b): Batch => Batch::fromArray($b), $m->batches ?? []),
        );
    }
}
