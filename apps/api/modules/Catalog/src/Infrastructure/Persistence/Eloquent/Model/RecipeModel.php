<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $food_id
 * @property string $author_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property int $prep_time_minutes
 * @property int $cook_time_minutes
 * @property string $difficulty
 * @property int $serving_size
 * @property array<int, array<string, mixed>> $ingredients
 * @property array<int, array<string, mixed>> $steps
 * @property array<int, string> $tips
 * @property array<int, string> $tags
 * @property array<int, string> $related_recipe_ids
 * @property string $status
 * @property int $version
 * @property float $rating_average
 * @property int $rating_count
 */
final class RecipeModel extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_recipes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ingredients' => 'array',
            'steps' => 'array',
            'tips' => 'array',
            'tags' => 'array',
            'related_recipe_ids' => 'array',
            'prep_time_minutes' => 'integer',
            'cook_time_minutes' => 'integer',
            'serving_size' => 'integer',
            'version' => 'integer',
            'rating_average' => 'float',
            'rating_count' => 'integer',
        ];
    }
}
