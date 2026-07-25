<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array<int, array<string, string>> $local_names
 * @property array<string, mixed>|null $nutrition
 */
final class IngredientModel extends Model
{
    protected $table = 'catalog_ingredients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['local_names' => 'array', 'nutrition' => 'array'];
    }
}
