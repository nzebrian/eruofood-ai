<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $type
 * @property string $merchant_type
 * @property string $merchant_id
 * @property string $order_id
 * @property string $payment_id
 * @property string|null $refund_id
 * @property string $currency
 * @property int $gross_minor
 * @property int $commission_minor
 * @property int $fee_minor
 * @property int $net_minor
 * @property int $commission_rate_bps
 * @property bool $ledger_posted
 * @property string|null $correlation_id
 * @property DateTimeInterface $accrued_at
 * @property DateTimeInterface $created_at
 */
final class PayableAccrualModel extends Model
{
    protected $table = 'payments_payable_accruals';

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
            'commission_rate_bps' => 'integer',
            'ledger_posted' => 'boolean',
            'accrued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
