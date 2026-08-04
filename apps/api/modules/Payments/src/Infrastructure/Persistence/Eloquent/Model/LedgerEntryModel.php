<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $correlation_id
 * @property string $account
 * @property string $direction
 * @property int $amount_minor
 * @property string $currency
 * @property string $type
 * @property string|null $reference
 * @property DateTimeInterface $posted_at
 */
final class LedgerEntryModel extends Model
{
    protected $table = 'payments_ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'posted_at' => 'datetime',
        ];
    }
}
