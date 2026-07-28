<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class SettingModel extends Model
{
    protected $table = 'admin_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'secret' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
