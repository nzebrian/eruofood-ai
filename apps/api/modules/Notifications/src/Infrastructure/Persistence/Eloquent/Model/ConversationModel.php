<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $type
 * @property array<array-key, mixed> $participant_ids
 * @property string|null $subject
 * @property string|null $context_ref
 * @property DateTimeInterface $last_message_at
 * @property DateTimeInterface $created_at
 */
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
