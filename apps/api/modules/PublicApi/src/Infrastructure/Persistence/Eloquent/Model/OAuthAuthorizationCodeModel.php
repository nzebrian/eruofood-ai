<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $hashed_code
 * @property string $client_id
 * @property string $subject_user_id
 * @property string $redirect_uri
 * @property array<array-key, mixed> $scopes
 * @property string $code_challenge
 * @property string $code_challenge_method
 * @property DateTimeInterface $expires_at
 * @property DateTimeInterface|null $consumed_at
 */
final class OAuthAuthorizationCodeModel extends Model
{
    protected $table = 'oauth_authorization_codes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
