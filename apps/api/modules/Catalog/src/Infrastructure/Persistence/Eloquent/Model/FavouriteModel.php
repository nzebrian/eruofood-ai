<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $recipe_id
 */
final class FavouriteModel extends Model
{
    protected $table = 'catalog_recipe_favourites';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
