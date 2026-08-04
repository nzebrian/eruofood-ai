<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $report_key
 * @property string $cadence
 * @property string $format
 * @property array<array-key, mixed> $recipients
 * @property bool $active
 * @property DateTimeInterface $next_run_at
 * @property DateTimeInterface|null $last_run_at
 * @property DateTimeInterface $created_at
 */
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
