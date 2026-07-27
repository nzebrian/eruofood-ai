<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class PaymentModel extends Model
{
    protected $table = 'payments_payments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'splits' => 'array',
            'timeline' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
