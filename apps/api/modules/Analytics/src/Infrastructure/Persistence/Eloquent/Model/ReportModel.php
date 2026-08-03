<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property string $title
 * @property DateTimeInterface $range_from
 * @property DateTimeInterface $range_to
 * @property array<array-key, mixed> $columns
 * @property array<array-key, mixed> $rows
 * @property string $status
 * @property DateTimeInterface $generated_at
 */
final class ReportModel extends Model
{
    protected $table = 'analytics_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
            'range_from' => 'date',
            'range_to' => 'date',
            'generated_at' => 'datetime',
        ];
    }
}
