<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reward_id
 * @property string $user_id
 * @property string $code
 * @property int $points_spent
 * @property string $benefit_type
 * @property int $benefit_value
 * @property string $status
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class RedemptionModel extends Model
{
    protected $table = 'loyalty_redemptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'points_spent' => 'integer',
            'benefit_value' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
