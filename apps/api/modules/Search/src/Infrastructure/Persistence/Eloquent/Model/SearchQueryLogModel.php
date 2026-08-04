<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $term
 * @property string $type
 * @property int $result_count
 * @property string|null $user_id
 * @property DateTimeInterface $created_at
 */
final class SearchQueryLogModel extends Model
{
    protected $table = 'search_query_log';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'result_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
