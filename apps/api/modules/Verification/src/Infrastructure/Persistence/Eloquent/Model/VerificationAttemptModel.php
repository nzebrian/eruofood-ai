<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $case_id
 * @property string $provider
 * @property string $provider_reference
 * @property string $status
 * @property string|null $raw_provider_status
 * @property string|null $reason_code
 * @property DateTimeInterface $started_at
 * @property DateTimeInterface|null $decided_at
 */
final class VerificationAttemptModel extends Model
{
    protected $table = 'verification_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
