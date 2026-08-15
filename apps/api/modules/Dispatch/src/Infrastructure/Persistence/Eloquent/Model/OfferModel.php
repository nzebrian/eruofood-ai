<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $rider_id
 * @property string $delivery_id
 * @property string|null $vehicle_id
 * @property float $score
 * @property array<string, mixed>|null $score_breakdown
 * @property int|null $eta_seconds
 * @property int|null $distance_metres
 * @property string $state
 * @property DateTimeInterface|null $responded_at
 * @property string|null $decline_reason
 * @property DateTimeInterface $offered_at
 * @property DateTimeInterface $expires_at
 * @property int $version
 */
final class OfferModel extends Model
{
    protected $table = 'dispatch_offers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'score_breakdown' => 'array',
            'eta_seconds' => 'integer',
            'distance_metres' => 'integer',
            'responded_at' => 'datetime',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
