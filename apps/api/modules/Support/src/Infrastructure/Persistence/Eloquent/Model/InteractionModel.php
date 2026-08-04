<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $kind
 * @property string $summary
 * @property string|null $ref
 * @property string $source
 * @property DateTimeInterface $occurred_at
 */
final class InteractionModel extends Model
{
    protected $table = 'support_interactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
