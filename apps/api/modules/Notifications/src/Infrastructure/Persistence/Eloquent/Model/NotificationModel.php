<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class NotificationModel extends Model
{
    protected $table = 'notifications_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'read_at' => 'datetime',
            'timeline' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
