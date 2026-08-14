<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $rider_id
 * @property string $user_id
 * @property float $latitude
 * @property float $longitude
 * @property float|null $accuracy_metres
 * @property float|null $heading_degrees
 * @property float|null $speed_mps
 * @property string $source
 * @property DateTimeInterface $recorded_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class RiderLocationModel extends Model
{
    protected $table = 'geo_rider_locations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $primaryKey = 'rider_id';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_metres' => 'float',
            'heading_degrees' => 'float',
            'speed_mps' => 'float',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
