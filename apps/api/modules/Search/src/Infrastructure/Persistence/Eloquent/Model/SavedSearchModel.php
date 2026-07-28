<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
