<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $group
 * @property string|null $value
 * @property bool $secret
 * @property string|null $description
 * @property DateTimeInterface $updated_at
 */
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
