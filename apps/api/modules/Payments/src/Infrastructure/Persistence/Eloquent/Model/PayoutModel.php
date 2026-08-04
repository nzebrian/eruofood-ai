<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $payee_type
 * @property string $payee_id
 * @property int $amount_minor
 * @property string $currency
 * @property array<array-key, mixed> $destination
 * @property string $status
 * @property string|null $provider_reference
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $paid_at
 */
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
