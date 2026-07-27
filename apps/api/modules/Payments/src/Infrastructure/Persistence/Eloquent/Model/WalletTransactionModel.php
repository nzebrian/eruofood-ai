<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class WalletTransactionModel extends Model
{
    protected $table = 'payments_wallet_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
