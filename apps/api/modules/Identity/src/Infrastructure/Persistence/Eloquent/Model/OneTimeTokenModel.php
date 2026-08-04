<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-use, expiring tokens for email verification and password reset.
 * Stored hashed and deleted on consumption.
 *
 * @property string $id
 * @property string $purpose
 * @property string $subject
 * @property string $token_hash
 * @property DateTimeInterface $expires_at
 */
final class OneTimeTokenModel extends Model
{
    protected $table = 'identity_one_time_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
