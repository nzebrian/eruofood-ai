<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
