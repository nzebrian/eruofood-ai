<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $sender_id
 * @property string $type
 * @property string $body
 * @property array<array-key, mixed> $attachments
 * @property array<array-key, mixed> $read_by
 * @property DateTimeInterface $created_at
 */
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
