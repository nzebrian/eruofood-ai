<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property int $count
 * @property float $average
 * @property array<array-key, mixed> $distribution
 * @property int $verified_count
 * @property DateTimeInterface $updated_at
 */
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
