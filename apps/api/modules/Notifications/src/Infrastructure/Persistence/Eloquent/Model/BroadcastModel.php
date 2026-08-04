<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $title
 * @property string $body
 * @property string $category
 * @property array<array-key, mixed> $channels
 * @property string $segment
 * @property DateTimeInterface|null $scheduled_for
 * @property bool $sent
 * @property int $recipient_count
 * @property DateTimeInterface $created_at
 */
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
