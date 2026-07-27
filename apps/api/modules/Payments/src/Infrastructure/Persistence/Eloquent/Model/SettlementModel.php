<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class SettlementModel extends Model
{
    protected $table = 'payments_settlements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'commission_minor' => 'integer',
            'fees_minor' => 'integer',
            'net_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
