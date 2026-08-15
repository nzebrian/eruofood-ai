<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $rider_id
 * @property string $type
 * @property string|null $registration_number
 * @property string|null $make
 * @property string|null $model
 * @property string|null $colour
 * @property int|null $capacity_kg
 * @property int|null $capacity_litres
 * @property string $status
 * @property string $verification_state
 * @property DateTimeInterface|null $verified_at
 * @property string|null $verified_by
 * @property string|null $verification_note
 * @property DateTimeInterface|null $insurance_expires_at
 * @property DateTimeInterface|null $roadworthiness_expires_at
 * @property DateTimeInterface|null $licence_expires_at
 * @property bool $is_primary
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 * @property int $version
 */
final class VehicleModel extends Model
{
    protected $table = 'dispatch_vehicles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'capacity_litres' => 'integer',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'insurance_expires_at' => 'datetime',
            'roadworthiness_expires_at' => 'datetime',
            'licence_expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
