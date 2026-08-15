<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $delivery_id
 * @property string $order_id
 * @property string $vendor_id
 * @property float $pickup_lat
 * @property float $pickup_lng
 * @property float $dropoff_lat
 * @property float $dropoff_lng
 * @property string $required_vehicle_type
 * @property int|null $load_kg
 * @property int|null $load_litres
 * @property string|null $zone_id
 * @property string $state
 * @property int $attempt_count
 * @property int $max_attempts
 * @property string|null $assigned_rider_id
 * @property DateTimeInterface|null $assigned_at
 * @property string|null $failure_reason
 * @property DateTimeInterface|null $failed_at
 * @property DateTimeInterface $expires_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 * @property int $version
 */
final class DispatchRequestModel extends Model
{
    protected $table = 'dispatch_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'load_kg' => 'integer',
            'load_litres' => 'integer',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'assigned_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
