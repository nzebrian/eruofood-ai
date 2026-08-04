<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $payee_type
 * @property string $payee_id
 * @property int $gross_minor
 * @property int $commission_minor
 * @property int $fees_minor
 * @property int $net_minor
 * @property string $currency
 * @property string $status
 * @property string|null $payout_id
 * @property DateTimeInterface $period_start
 * @property DateTimeInterface $period_end
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $completed_at
 */
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
