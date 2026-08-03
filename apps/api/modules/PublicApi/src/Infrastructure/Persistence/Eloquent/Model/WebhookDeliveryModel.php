<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $webhook_id
 * @property string $event_id
 * @property string $event_name
 * @property string $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $last_response_code
 * @property string|null $last_error
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $next_attempt_at
 * @property DateTimeInterface|null $delivered_at
 */
final class WebhookDeliveryModel extends Model
{
    protected $table = 'developer_webhook_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_response_code' => 'integer',
            'created_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
