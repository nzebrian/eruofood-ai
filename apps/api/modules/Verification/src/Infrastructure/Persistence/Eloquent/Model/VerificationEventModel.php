<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $case_id
 * @property string $from_status
 * @property string $to_status
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $reason_code
 * @property string|null $note
 * @property DateTimeInterface $occurred_at
 */
final class VerificationEventModel extends Model
{
    protected $table = 'verification_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
