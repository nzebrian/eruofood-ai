<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $query_id
 * @property string $document_id
 * @property int $position
 * @property bool $from_recommendation
 * @property DateTimeInterface $created_at
 */
final class SearchClickModel extends Model
{
    protected $table = 'search_clicks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'from_recommendation' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
