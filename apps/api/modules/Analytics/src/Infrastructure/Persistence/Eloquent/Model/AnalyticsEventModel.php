<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $category
 * @property string|null $actor_id
 * @property int $value
 * @property array<array-key, mixed> $dimensions
 * @property DateTimeInterface $occurred_at
 */
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
