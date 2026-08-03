<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string $benefit_type
 * @property int $benefit_value
 * @property int $points_cost
 * @property int|null $stock
 * @property bool $active
 * @property DateTimeInterface|null $starts_at
 * @property DateTimeInterface|null $ends_at
 * @property DateTimeInterface $created_at
 */
final class RewardModel extends Model
{
    protected $table = 'loyalty_rewards';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'benefit_value' => 'integer',
            'points_cost' => 'integer',
            'stock' => 'integer',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
