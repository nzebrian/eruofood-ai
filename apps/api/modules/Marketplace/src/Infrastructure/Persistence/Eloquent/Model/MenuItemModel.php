<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $vendor_id
 * @property string|null $category_id
 * @property string $name
 * @property string|null $description
 * @property bool $description_ai_generated
 * @property int $base_price_minor
 * @property string $currency
 * @property list<array<string, mixed>> $variants
 * @property bool $available
 * @property list<string> $images
 * @property list<string> $tags
 * @property bool $featured
 * @property array<string, mixed>|null $promotion
 * @property bool $track_inventory
 * @property int $stock
 * @property int|null $calories
 * @property string|null $nutrition_item_id
 */
final class MenuItemModel extends Model
{
    protected $table = 'marketplace_menu_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'description_ai_generated' => 'boolean',
            'base_price_minor' => 'integer',
            'variants' => 'array',
            'available' => 'boolean',
            'images' => 'array',
            'tags' => 'array',
            'featured' => 'boolean',
            'promotion' => 'array',
            'track_inventory' => 'boolean',
            'stock' => 'integer',
            'calories' => 'integer',
        ];
    }
}
