<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $kind
 * @property string $subject_type
 * @property string $subject_id
 * @property int $expected_minor
 * @property int $observed_minor
 * @property string $currency
 * @property string $state
 * @property string|null $detail
 * @property DateTimeInterface $opened_at
 * @property DateTimeInterface|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolution_note
 * @property string|null $compensating_posting_id
 * @property string|null $correlation_id
 * @property int $version
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class ReconciliationCaseModel extends Model
{
    protected $table = 'payments_reconciliation_cases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expected_minor' => 'integer',
            'observed_minor' => 'integer',
            'version' => 'integer',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
