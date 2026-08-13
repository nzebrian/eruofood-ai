<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Idempotency\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $scope
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string|null $user_id
 * @property string $state
 * @property array<string, mixed>|null $response_snapshot
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface|null $completed_at
 * @property DateTimeInterface $expires_at
 */
final class IdempotencyKeyModel extends Model
{
    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_COMPLETED = 'completed';

    protected $table = 'shared_idempotency_keys';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
