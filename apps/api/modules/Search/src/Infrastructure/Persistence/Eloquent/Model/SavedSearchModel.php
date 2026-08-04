<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string|null $term
 * @property string $type
 * @property string $sort
 * @property array<array-key, mixed> $filters
 * @property DateTimeInterface $created_at
 */
final class SavedSearchModel extends Model
{
    protected $table = 'search_saved_searches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
