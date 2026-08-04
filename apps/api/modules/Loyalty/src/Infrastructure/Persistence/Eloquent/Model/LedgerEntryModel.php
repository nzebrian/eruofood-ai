<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $account_id
 * @property string $type
 * @property int $points
 * @property string $reason
 * @property string|null $reference
 * @property int $balance_after
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $expires_at
 */
final class LedgerEntryModel extends Model
{
    protected $table = 'loyalty_ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
