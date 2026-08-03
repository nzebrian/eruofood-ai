<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $referrer_user_id
 * @property string $referee_user_id
 * @property string $status
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $qualified_at
 */
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
