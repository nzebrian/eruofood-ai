<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $vendor_id
 * @property string|null $rider_id
 * @property string $status
 * @property int $fee_minor
 * @property string $currency
 * @property string|null $zone_name
 * @property float|null $pickup_lat
 * @property float|null $pickup_lng
 * @property float|null $dropoff_lat
 * @property float|null $dropoff_lng
 * @property list<array{lat: float, lng: float, at: string}> $track_points
 * @property DateTimeInterface|null $assigned_at
 * @property DateTimeInterface|null $delivered_at
 * @property DateTimeInterface $created_at
 */
final class DeliveryModel extends Model
{
    protected $table = 'marketplace_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fee_minor' => 'integer',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'track_points' => 'array',
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
