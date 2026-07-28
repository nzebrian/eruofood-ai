<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
