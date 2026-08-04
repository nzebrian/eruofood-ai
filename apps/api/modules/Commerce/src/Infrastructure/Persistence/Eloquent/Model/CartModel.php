<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string|null $coupon_code
 * @property list<array<string, mixed>> $items
 */
final class CartModel extends Model
{
    protected $table = 'commerce_carts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
