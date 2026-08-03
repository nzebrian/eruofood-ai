<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property string $period
 * @property string $start_date
 * @property list<array<string, mixed>> $entries
 * @property DateTimeInterface $created_at
 */
final class MealPlanModel extends Model
{
    protected $table = 'nutrition_meal_plans';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entries' => 'array'];
    }
}
