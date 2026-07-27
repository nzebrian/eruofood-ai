<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
