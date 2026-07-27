<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class WalletModel extends Model
{
    protected $table = 'payments_wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'low_balance_threshold' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
