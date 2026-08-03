<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property string|null $excerpt
 * @property string $category
 * @property string $status
 * @property int $version
 * @property array<array-key, mixed> $tags
 * @property int $helpful_yes
 * @property int $helpful_no
 * @property string|null $author_id
 * @property DateTimeInterface|null $published_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class ArticleModel extends Model
{
    protected $table = 'support_articles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'version' => 'integer',
            'helpful_yes' => 'integer',
            'helpful_no' => 'integer',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
