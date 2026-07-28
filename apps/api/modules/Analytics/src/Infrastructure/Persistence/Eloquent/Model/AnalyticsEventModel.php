<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class AnalyticsEventModel extends Model
{
    protected $table = 'analytics_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'dimensions' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
