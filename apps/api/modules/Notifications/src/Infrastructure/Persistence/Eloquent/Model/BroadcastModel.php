<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class BroadcastModel extends Model
{
    protected $table = 'notifications_broadcasts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'scheduled_for' => 'datetime',
            'sent' => 'boolean',
            'recipient_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
