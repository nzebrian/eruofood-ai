<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property int $balance
 * @property int $lifetime_points
 * @property string $tier_key
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
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
