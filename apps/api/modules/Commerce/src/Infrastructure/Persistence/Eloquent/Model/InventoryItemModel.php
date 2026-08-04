<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $product_id
 * @property string|null $variant_sku
 * @property string|null $warehouse_id
 * @property string|null $supplier_id
 * @property int $quantity
 * @property int $low_stock_threshold
 * @property list<array<string, mixed>> $batches
 */
final class InventoryItemModel extends Model
{
    protected $table = 'commerce_inventory_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'batches' => 'array',
        ];
    }
}
