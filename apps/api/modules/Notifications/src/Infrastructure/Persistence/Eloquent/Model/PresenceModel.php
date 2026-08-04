<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string $status
 * @property DateTimeInterface $updated_at
 */
final class PresenceModel extends Model
{
    protected $table = 'notifications_presence';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }
}
