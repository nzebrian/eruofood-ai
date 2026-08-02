<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
