<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $payment_id
 * @property string|null $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property bool $partial
 * @property string $reason
 * @property string $status
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $completed_at
 */
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
