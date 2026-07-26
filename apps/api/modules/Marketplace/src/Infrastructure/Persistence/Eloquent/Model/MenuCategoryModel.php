<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $vendor_id
 * @property string $name
 * @property int $sort_order
 */
final class MenuCategoryModel extends Model
{
    protected $table = 'marketplace_menu_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
