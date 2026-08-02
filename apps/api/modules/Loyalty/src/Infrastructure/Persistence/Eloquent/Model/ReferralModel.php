<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class ReferralModel extends Model
{
    protected $table = 'loyalty_referrals';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'qualified_at' => 'datetime',
        ];
    }
}
