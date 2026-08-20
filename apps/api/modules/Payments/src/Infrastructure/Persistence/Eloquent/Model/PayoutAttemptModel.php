<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $settlement_run_id
 * @property int $attempt_no
 * @property string $provider
 * @property string|null $provider_reference
 * @property int $amount_minor
 * @property string $currency
 * @property string $state
 * @property string|null $failure_reason
 * @property string $idempotency_key
 * @property string|null $correlation_id
 * @property string|null $raw_response_digest
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $submitted_at
 * @property DateTimeInterface|null $settled_at
 * @property DateTimeInterface $updated_at
 */
final class PayoutAttemptModel extends Model
{
    protected $table = 'payments_payout_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
