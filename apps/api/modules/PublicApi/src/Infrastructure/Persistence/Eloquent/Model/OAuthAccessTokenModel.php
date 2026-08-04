<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $hashed_token
 * @property string $client_id
 * @property string $application_id
 * @property string $developer_id
 * @property string|null $subject_user_id
 * @property array<array-key, mixed> $scopes
 * @property DateTimeInterface $expires_at
 * @property DateTimeInterface|null $revoked_at
 * @property DateTimeInterface $created_at
 */
final class OAuthAccessTokenModel extends Model
{
    protected $table = 'oauth_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
