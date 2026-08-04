<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $developer_id
 * @property string $name
 * @property string|null $description
 * @property array<array-key, mixed> $scopes
 * @property string $status
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class ApplicationModel extends Model
{
    protected $table = 'developer_applications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
