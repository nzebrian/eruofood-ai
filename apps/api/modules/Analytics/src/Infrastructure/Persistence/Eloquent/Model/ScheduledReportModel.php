<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class ScheduledReportModel extends Model
{
    protected $table = 'analytics_scheduled_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
