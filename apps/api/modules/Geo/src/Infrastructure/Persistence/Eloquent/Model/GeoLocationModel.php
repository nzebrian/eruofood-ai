<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $address_text
 * @property string|null $formatted_address
 * @property string|null $line1
 * @property string|null $line2
 * @property string|null $district
 * @property string|null $locality
 * @property string|null $admin_area
 * @property string|null $sub_admin_area
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $country_name
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string $source
 * @property string $precision
 * @property float|null $confidence
 * @property string $verification_status
 * @property string|null $provider
 * @property string|null $provider_place_id
 * @property DateTimeInterface|null $geocoded_at
 * @property DateTimeInterface|null $verified_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class GeoLocationModel extends Model
{
    protected $table = 'geo_locations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'confidence' => 'float',
            'geocoded_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
