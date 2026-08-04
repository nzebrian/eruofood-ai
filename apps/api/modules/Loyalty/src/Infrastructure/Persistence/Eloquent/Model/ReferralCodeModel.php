<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property string $user_id
 * @property DateTimeInterface $created_at
 */
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
