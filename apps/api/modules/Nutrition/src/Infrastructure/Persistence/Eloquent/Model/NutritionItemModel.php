<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $category
 * @property string $serving_label
 * @property float $serving_grams
 * @property array<array-key, mixed> $nutrition
 * @property string|null $food_id
 */
final class NutritionItemModel extends Model
{
    protected $table = 'nutrition_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'serving_grams' => 'float',
            'nutrition' => 'array',
        ];
    }
}
