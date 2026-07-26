<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $user_id
 * @property string $feature
 * @property string $title
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ConversationModel extends Model
{
    protected $table = 'ai_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return HasMany<ConversationMessageModel, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessageModel::class, 'conversation_id')->orderBy('position');
    }
}
