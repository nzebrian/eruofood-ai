<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $provider
 * @property string $capability
 * @property bool $succeeded
 * @property bool $served_from_cache
 * @property int|null $duration_ms
 * @property string|null $failure_code
 * @property DateTimeInterface $requested_at
 */
final class ProviderRequestModel extends Model
{
    protected $table = 'geo_provider_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'served_from_cache' => 'boolean',
            'duration_ms' => 'integer',
            'requested_at' => 'datetime',
        ];
    }
}
