<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $admin_user_id
 * @property string $target_user_id
 * @property string $reason
 * @property DateTimeInterface $started_at
 * @property DateTimeInterface|null $ended_at
 */
final class ImpersonationModel extends Model
{
    protected $table = 'admin_impersonations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
