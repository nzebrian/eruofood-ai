<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $application_id
 * @property string $name
 * @property string $prefix
 * @property string $hashed_secret
 * @property array<array-key, mixed> $scopes
 * @property string $status
 * @property DateTimeInterface|null $expires_at
 * @property DateTimeInterface|null $last_used_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $revoked_at
 */
final class ApiKeyModel extends Model
{
    protected $table = 'developer_api_keys';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
