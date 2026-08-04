<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string|null $vendor_id
 * @property list<array<string, mixed>> $items
 * @property string $currency
 */
final class CartModel extends Model
{
    protected $table = 'marketplace_carts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
