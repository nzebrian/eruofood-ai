<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $cache_key
 * @property float $origin_latitude
 * @property float $origin_longitude
 * @property float $destination_latitude
 * @property float $destination_longitude
 * @property string $travel_mode
 * @property bool $traffic_aware
 * @property int $distance_metres
 * @property int $duration_seconds
 * @property int|null $duration_in_traffic_seconds
 * @property string $provider
 * @property string|null $provider_route_id
 * @property DateTimeInterface $calculated_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class RouteCacheModel extends Model
{
    protected $table = 'geo_route_cache';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origin_latitude' => 'float',
            'origin_longitude' => 'float',
            'destination_latitude' => 'float',
            'destination_longitude' => 'float',
            'traffic_aware' => 'boolean',
            'distance_metres' => 'integer',
            'duration_seconds' => 'integer',
            'duration_in_traffic_seconds' => 'integer',
            'calculated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
