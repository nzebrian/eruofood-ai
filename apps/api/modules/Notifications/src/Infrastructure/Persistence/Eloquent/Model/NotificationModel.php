<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $category
 * @property string $channel
 * @property string $template_key
 * @property array<array-key, mixed> $data
 * @property string $subject
 * @property string $body
 * @property string $priority
 * @property string $status
 * @property int $attempts
 * @property DateTimeInterface|null $scheduled_for
 * @property DateTimeInterface|null $read_at
 * @property array<array-key, mixed> $timeline
 * @property string|null $provider_message_id
 * @property string|null $correlation_id
 * @property string $notification_class
 * @property bool $retryable
 * @property DateTimeInterface $created_at
 */
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
            'retryable' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
