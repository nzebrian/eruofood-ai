<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property array<array-key, mixed> $channels_by_category
 * @property array<array-key, mixed> $quiet_hours
 * @property string $language
 * @property int $max_per_day
 */
final class NotificationPreferenceModel extends Model
{
    protected $table = 'notifications_preferences';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'channels_by_category' => 'array',
            'quiet_hours' => 'array',
            'max_per_day' => 'integer',
        ];
    }
}
