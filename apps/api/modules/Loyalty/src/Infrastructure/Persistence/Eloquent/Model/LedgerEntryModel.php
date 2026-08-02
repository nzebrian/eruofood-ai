<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
