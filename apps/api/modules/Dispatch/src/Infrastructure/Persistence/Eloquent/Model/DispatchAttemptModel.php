<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property int $attempt_number
 * @property int $search_radius_metres
 * @property int $raw_candidate_count
 * @property int $eligible_candidate_count
 * @property array<string, int>|null $rejection_breakdown
 * @property string|null $offered_rider_id
 * @property float|null $offered_score
 * @property string|null $outcome
 * @property int $duration_ms
 * @property DateTimeInterface $started_at
 * @property DateTimeInterface $completed_at
 */
final class DispatchAttemptModel extends Model
{
    protected $table = 'dispatch_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'search_radius_metres' => 'integer',
            'raw_candidate_count' => 'integer',
            'eligible_candidate_count' => 'integer',
            'rejection_breakdown' => 'array',
            'offered_score' => 'float',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
