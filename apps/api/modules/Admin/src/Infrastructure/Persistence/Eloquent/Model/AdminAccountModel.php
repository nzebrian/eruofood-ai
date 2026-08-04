<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property array<array-key, mixed> $roles
 * @property array<array-key, mixed> $extra_permissions
 * @property string $status
 * @property DateTimeInterface $created_at
 */
final class AdminAccountModel extends Model
{
    protected $table = 'admin_accounts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'extra_permissions' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
