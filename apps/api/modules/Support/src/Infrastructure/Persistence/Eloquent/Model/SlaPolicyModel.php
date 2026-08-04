<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $priority
 * @property int $first_response_minutes
 * @property int $resolution_minutes
 */
final class SlaPolicyModel extends Model
{
    protected $table = 'support_sla_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }
}
