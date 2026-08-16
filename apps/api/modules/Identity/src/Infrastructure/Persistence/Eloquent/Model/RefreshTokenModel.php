<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A refresh token = a session/device. The token itself is stored only as a
 * SHA-256 hash; the plaintext is shown to the client once.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_id
 * @property string|null $device_name
 * @property string|null $platform
 * @property DateTimeInterface|null $last_activity_at
 * @property DateTimeInterface|null $reuse_detected_at
 * @property DateTimeInterface $expires_at
 * @property DateTimeInterface|null $revoked_at
 * @property DateTimeInterface|null $last_used_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $updated_at
 */
final class RefreshTokenModel extends Model
{
    protected $table = 'identity_refresh_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reuse_detected_at' => 'datetime',
        ];
    }
}
