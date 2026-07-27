<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class ConversationModel extends Model
{
    protected $table = 'notifications_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'participant_ids' => 'array',
            'last_message_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
