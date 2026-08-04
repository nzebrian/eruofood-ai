<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $metric
 * @property string $category
 * @property string $bucket_date
 * @property string|null $dimension_key
 * @property string|null $dimension_value
 * @property int $count
 * @property int $sum_value
 */
final class MetricBucketModel extends Model
{
    protected $table = 'analytics_metrics';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'sum_value' => 'integer',
            'bucket_date' => 'date',
        ];
    }
}
