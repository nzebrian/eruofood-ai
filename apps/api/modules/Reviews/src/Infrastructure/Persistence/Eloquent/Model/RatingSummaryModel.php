<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class RatingSummaryModel extends Model
{
    protected $table = 'review_rating_summaries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'distribution' => 'array',
            'count' => 'integer',
            'average' => 'float',
            'verified_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
