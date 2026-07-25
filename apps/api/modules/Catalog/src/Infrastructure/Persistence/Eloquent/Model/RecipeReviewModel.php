<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $recipe_id
 * @property string $user_id
 * @property int $rating
 * @property string|null $comment
 */
final class RecipeReviewModel extends Model
{
    protected $table = 'catalog_recipe_reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
