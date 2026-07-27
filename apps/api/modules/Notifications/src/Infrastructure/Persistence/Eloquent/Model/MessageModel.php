<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class MessageModel extends Model
{
    protected $table = 'notifications_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'read_by' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
