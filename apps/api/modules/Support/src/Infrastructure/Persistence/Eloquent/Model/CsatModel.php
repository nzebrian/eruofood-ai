<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ticket_id
 * @property string $user_id
 * @property int $score
 * @property string|null $comment
 * @property string|null $agent_id
 * @property DateTimeInterface $created_at
 */
final class CsatModel extends Model
{
    protected $table = 'support_csat';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
