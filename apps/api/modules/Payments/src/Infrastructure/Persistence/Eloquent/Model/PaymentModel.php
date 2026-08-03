<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reference
 * @property string|null $order_id
 * @property string $payer_user_id
 * @property int $amount_minor
 * @property int $refunded_minor
 * @property string $currency
 * @property string $status
 * @property string $provider
 * @property string $method_type
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property array<array-key, mixed> $splits
 * @property string|null $failure_reason
 * @property array<array-key, mixed> $timeline
 * @property DateTimeInterface $created_at
 */
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
