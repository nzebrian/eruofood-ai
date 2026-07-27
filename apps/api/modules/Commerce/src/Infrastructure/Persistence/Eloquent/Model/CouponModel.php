<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property int $min_spend_minor
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $active
 */
final class CouponModel extends Model
{
    protected $table = 'commerce_coupons';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_spend_minor' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'expires_at' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
