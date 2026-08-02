<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class ReferralCodeModel extends Model
{
    protected $table = 'loyalty_referral_codes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'code';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
