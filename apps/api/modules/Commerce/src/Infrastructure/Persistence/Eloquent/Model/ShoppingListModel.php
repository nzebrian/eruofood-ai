<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property list<array{name: string, quantity: int, product_id: string|null, bought: bool}> $lines
 */
final class ShoppingListModel extends Model
{
    protected $table = 'commerce_shopping_lists';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }
}
