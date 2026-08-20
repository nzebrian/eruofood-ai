<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $settlement_run_id
 * @property string $accrual_id
 * @property string $currency
 * @property int $net_minor
 * @property DateTimeInterface $created_at
 */
final class SettlementLineModel extends Model
{
    protected $table = 'payments_settlement_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'net_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
