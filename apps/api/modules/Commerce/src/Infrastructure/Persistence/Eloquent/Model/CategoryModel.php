<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property string|null $department
 * @property string|null $parent_id
 * @property int $sort_order
 */
final class CategoryModel extends Model
{
    protected $table = 'commerce_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
