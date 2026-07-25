<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $category_id
 * @property string $region
 * @property array<int, string> $states
 * @property array<int, array<string, string>> $local_names
 * @property array<string, mixed>|null $nutrition
 * @property array<int, string> $images
 * @property string|null $video_url
 * @property array<int, string> $tags
 * @property string $status
 */
final class FoodModel extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_foods';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'states' => 'array',
            'local_names' => 'array',
            'nutrition' => 'array',
            'images' => 'array',
            'tags' => 'array',
        ];
    }
}
