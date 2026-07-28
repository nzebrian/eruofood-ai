<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class SearchDocumentModel extends Model
{
    protected $table = 'search_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'facets' => 'array',
            'embedding' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'popularity' => 'integer',
            'rating' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
