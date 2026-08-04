<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable recipe snapshot per version.
 *
 * @property string $id
 * @property string $recipe_id
 * @property int $version
 * @property array<array-key, mixed> $snapshot
 * @property DateTimeInterface $created_at
 */
final class RecipeVersionModel extends Model
{
    protected $table = 'catalog_recipe_versions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
