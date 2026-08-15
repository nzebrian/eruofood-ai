<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $offer_id
 * @property string $delivery_id
 * @property string $rider_id
 * @property string|null $vehicle_id
 * @property string $state
 * @property int|null $eta_seconds
 * @property string|null $ended_reason
 * @property DateTimeInterface|null $ended_at
 * @property DateTimeInterface $accepted_at
 * @property DateTimeInterface $updated_at
 * @property int $version
 */
final class AssignmentModel extends Model
{
    protected $table = 'dispatch_assignments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'eta_seconds' => 'integer',
            'ended_at' => 'datetime',
            'accepted_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
