<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $title
 * @property string $image_url
 * @property string|null $link_url
 * @property string $placement
 * @property int $sort_order
 * @property bool $active
 * @property DateTimeInterface|null $starts_at
 * @property DateTimeInterface|null $ends_at
 * @property DateTimeInterface $created_at
 */
final class BannerModel extends Model
{
    protected $table = 'admin_banners';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
