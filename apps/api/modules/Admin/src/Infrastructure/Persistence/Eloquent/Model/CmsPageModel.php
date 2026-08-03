<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $type
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property string|null $excerpt
 * @property array<array-key, mixed> $seo
 * @property string $status
 * @property string $author_id
 * @property DateTimeInterface|null $published_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class CmsPageModel extends Model
{
    protected $table = 'admin_cms_pages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'seo' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
