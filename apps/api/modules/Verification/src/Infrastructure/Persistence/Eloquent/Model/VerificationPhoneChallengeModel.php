<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $phone
 * @property string $code_hash
 * @property DateTimeInterface $expires_at
 * @property int $attempts
 * @property DateTimeInterface|null $verified_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class VerificationPhoneChallengeModel extends Model
{
    protected $table = 'verification_phone_challenges';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
