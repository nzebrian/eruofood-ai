<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $plan
 * @property int $amount_minor
 * @property string $currency
 * @property string $interval
 * @property string $status
 * @property DateTimeInterface $next_billing_at
 * @property DateTimeInterface $created_at
 */
final class SubscriptionModel extends Model
{
    protected $table = 'payments_subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'next_billing_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
