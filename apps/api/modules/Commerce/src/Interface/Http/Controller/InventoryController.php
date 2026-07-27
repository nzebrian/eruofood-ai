<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\InventoryService;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\Supplier;
use EruoFood\Commerce\Domain\Inventory\Warehouse;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock, warehouse and supplier management (admin/ops). Guarded by role:admin at
 * the route layer.
 */
final readonly class InventoryController
{
    use RespondsWithData;

    public function __construct(
        private InventoryService $inventory,
        private CommercePresenter $presenter,
    ) {
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'variant_sku' => ['nullable', 'string', 'max:60'],
            'quantity' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'batch_number' => ['nullable', 'string', 'max:60'],
            'expires_at' => ['nullable', 'date'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->inventory->receive(
            (string) $data['product_id'],
            isset($data['variant_sku']) ? (string) $data['variant_sku'] : null,
            (int) $data['quantity'],
            isset($data['warehouse_id']) ? (string) $data['warehouse_id'] : null,
            isset($data['supplier_id']) ? (string) $data['supplier_id'] : null,
            isset($data['batch_number']) ? (string) $data['batch_number'] : null,
            isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
            isset($data['low_stock_threshold']) ? (int) $data['low_stock_threshold'] : null,
        );

        return $this->data($this->presenter->inventoryItem($item), 201);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer']]);
        $item = $this->inventory->adjust($id, (int) $data['delta']);

        return $this->data($this->presenter->inventoryItem($item));
    }

    public function forProduct(string $productId): JsonResponse
    {
        return $this->data(array_map(
            fn (InventoryItem $i): array => $this->presenter->inventoryItem($i),
            $this->inventory->forProduct($productId),
        ));
    }

    public function lowStock(Request $request): JsonResponse
    {
        $page = $this->inventory->lowStock((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (InventoryItem $i): array => $this->presenter->inventoryItem($i));
    }

    public function warehouses(): JsonResponse
    {
        return $this->data(array_map(
            fn (Warehouse $w): array => $this->presenter->warehouse($w),
            $this->inventory->warehouses(),
        ));
    }

    public function createWarehouse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'array'],
        ]);
        $warehouse = $this->inventory->createWarehouse(
            (string) $data['name'],
            isset($data['code']) ? (string) $data['code'] : null,
            isset($data['address']) && is_array($data['address']) ? Address::fromArray($data['address']) : null,
        );

        return $this->data($this->presenter->warehouse($warehouse), 201);
    }

    public function suppliers(): JsonResponse
    {
        return $this->data(array_map(
            fn (Supplier $s): array => $this->presenter->supplier($s),
            $this->inventory->suppliers(),
        ));
    }

    public function createSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $supplier = $this->inventory->createSupplier(
            (string) $data['name'],
            isset($data['contact_name']) ? (string) $data['contact_name'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
            isset($data['phone']) ? (string) $data['phone'] : null,
        );

        return $this->data($this->presenter->supplier($supplier), 201);
    }
}
