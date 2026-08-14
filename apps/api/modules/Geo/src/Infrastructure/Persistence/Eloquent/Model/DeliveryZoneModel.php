<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_type
 * @property string|null $owner_id
 * @property string $name
 * @property string $zone_type
 * @property string|null $centre_location_id
 * @property float|null $centre_latitude
 * @property float|null $centre_longitude
 * @property int|null $radius_metres
 * @property array<int, mixed>|null $polygon
 * @property float|null $bbox_min_lat
 * @property float|null $bbox_max_lat
 * @property float|null $bbox_min_lon
 * @property float|null $bbox_max_lon
 * @property string|null $country_code
 * @property string|null $admin_area
 * @property int|null $fee_minor
 * @property int|null $min_order_minor
 * @property bool $is_restricted
 * @property bool $is_active
 * @property int $priority
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class DeliveryZoneModel extends Model
{
    protected $table = 'geo_delivery_zones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'centre_latitude' => 'float',
            'centre_longitude' => 'float',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lon' => 'float',
            'bbox_max_lon' => 'float',
            'radius_metres' => 'integer',
            'fee_minor' => 'integer',
            'min_order_minor' => 'integer',
            'priority' => 'integer',
            'is_restricted' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
