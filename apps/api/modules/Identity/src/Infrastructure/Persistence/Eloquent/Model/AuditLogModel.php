<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail entry.
 *
 * @property string $id
 * @property string $action
 * @property string|null $actor_id
 * @property array<array-key, mixed> $context
 * @property string|null $ip_address
 */
final class AuditLogModel extends Model
{
    protected $table = 'identity_audit_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    // Audit rows are immutable: only created_at is meaningful.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
