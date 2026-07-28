<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
