<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property list<string> $product_ids
 */
final class WishlistModel extends Model
{
    protected $table = 'commerce_wishlists';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['product_ids' => 'array'];
    }
}
