<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class RefundModel extends Model
{
    protected $table = 'payments_refunds';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'partial' => 'boolean',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
