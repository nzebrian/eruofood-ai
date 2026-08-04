<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $application_id
 * @property string $developer_id
 * @property string $name
 * @property string|null $hashed_secret
 * @property bool $confidential
 * @property array<array-key, mixed> $grants
 * @property array<array-key, mixed> $redirect_uris
 * @property array<array-key, mixed> $allowed_scopes
 * @property DateTimeInterface $created_at
 */
final class OAuthClientModel extends Model
{
    protected $table = 'oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'confidential' => 'boolean',
            'grants' => 'array',
            'redirect_uris' => 'array',
            'allowed_scopes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
