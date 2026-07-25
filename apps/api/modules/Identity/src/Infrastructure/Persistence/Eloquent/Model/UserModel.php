<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent persistence model for a user. This is an infrastructure detail — the
 * domain never references it. The repository maps between this and the User
 * aggregate. Sensitive columns (2FA secret & recovery codes) are encrypted.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $password
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $avatar_path
 * @property array<int, string> $roles
 * @property array<string, mixed> $preferences
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 */
final class UserModel extends Model
{
    use SoftDeletes;

    protected $table = 'identity_users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'roles' => 'array',
            'preferences' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }
}
