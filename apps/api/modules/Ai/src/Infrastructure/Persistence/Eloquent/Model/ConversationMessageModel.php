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

    /** Messages are append-only: only created_at is tracked (no updated_at column). */
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
