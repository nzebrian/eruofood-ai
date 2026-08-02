<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class AccountModel extends Model
{
    protected $table = 'loyalty_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'lifetime_points' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
