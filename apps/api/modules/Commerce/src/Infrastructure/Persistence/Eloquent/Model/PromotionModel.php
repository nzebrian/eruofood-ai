<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $store_id
 * @property string $name
 * @property string $type
 * @property int $value
 * @property list<string> $product_ids
 * @property DateTimeInterface|null $starts_at
 * @property DateTimeInterface|null $ends_at
 * @property bool $flash_sale
 */
final class PromotionModel extends Model
{
    protected $table = 'commerce_promotions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'product_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'flash_sale' => 'boolean',
        ];
    }
}
