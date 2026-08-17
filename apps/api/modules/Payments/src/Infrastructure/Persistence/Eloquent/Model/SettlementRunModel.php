<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $merchant_type
 * @property string $merchant_id
 * @property string $currency
 * @property DateTimeInterface $window_start
 * @property DateTimeInterface $window_end
 * @property int $gross_minor
 * @property int $commission_minor
 * @property int $fee_minor
 * @property int $net_minor
 * @property string $state
 * @property string|null $idempotency_key
 * @property string $settlement_reference
 * @property string|null $correlation_id
 * @property string|null $computed_by
 * @property DateTimeInterface|null $computed_at
 * @property string|null $approved_by
 * @property DateTimeInterface|null $approved_at
 * @property string|null $executed_by
 * @property DateTimeInterface|null $executed_at
 * @property string|null $failure_reason
 * @property int $version
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class SettlementRunModel extends Model
{
    protected $table = 'payments_settlement_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'commission_minor' => 'integer',
            'fee_minor' => 'integer',
            'net_minor' => 'integer',
            'version' => 'integer',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'computed_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
