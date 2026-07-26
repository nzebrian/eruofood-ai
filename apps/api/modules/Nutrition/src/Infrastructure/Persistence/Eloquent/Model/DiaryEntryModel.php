<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $entry_date
 * @property string $meal_type
 * @property string $item_name
 * @property float $servings
 * @property string|null $nutrition_item_id
 * @property array<string, mixed> $nutrition
 * @property \Illuminate\Support\Carbon $created_at
 */
final class DiaryEntryModel extends Model
{
    protected $table = 'nutrition_diary_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'servings' => 'float',
            'nutrition' => 'array',
        ];
    }
}
