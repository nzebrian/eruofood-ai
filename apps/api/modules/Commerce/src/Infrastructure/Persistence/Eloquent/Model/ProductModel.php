<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $store_id
 * @property string|null $category_id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property string|null $department
 * @property string|null $description
 * @property bool $description_ai_generated
 * @property int $base_price_minor
 * @property list<array<string, mixed>> $variants
 * @property list<string> $images
 * @property list<string> $tags
 * @property string $status
 * @property bool $featured
 * @property string|null $barcode
 * @property string|null $brand
 * @property float $rating_average
 * @property int $rating_count
 */
final class ProductModel extends Model
{
    protected $table = 'commerce_products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'description_ai_generated' => 'boolean',
            'base_price_minor' => 'integer',
            'variants' => 'array',
            'images' => 'array',
            'tags' => 'array',
            'featured' => 'boolean',
            'rating_average' => 'float',
            'rating_count' => 'integer',
        ];
    }
}
