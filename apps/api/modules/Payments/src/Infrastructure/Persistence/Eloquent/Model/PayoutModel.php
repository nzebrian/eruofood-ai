<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class PayoutModel extends Model
{
    protected $table = 'payments_payouts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'destination' => 'array',
            'created_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
