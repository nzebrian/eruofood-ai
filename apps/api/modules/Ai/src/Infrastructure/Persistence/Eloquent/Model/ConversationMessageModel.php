<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int $position
 * @property string $role
 * @property string $content
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ConversationMessageModel extends Model
{
    protected $table = 'ai_conversation_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
