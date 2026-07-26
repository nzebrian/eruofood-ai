<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property float $weight_kg
 * @property float $height_cm
 * @property int $age
 * @property string $gender
 * @property string $activity_level
 * @property string $goal
 * @property list<string> $dietary_preferences
 * @property list<string> $allergies
 * @property list<string> $medical_restrictions
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class HealthProfileModel extends Model
{
    protected $table = 'nutrition_health_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'height_cm' => 'float',
            'age' => 'integer',
            'dietary_preferences' => 'array',
            'allergies' => 'array',
            'medical_restrictions' => 'array',
        ];
    }
}
