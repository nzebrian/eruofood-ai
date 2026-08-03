<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $actor_id
 * @property string $category
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<array-key, mixed> $context
 * @property string|null $ip
 * @property DateTimeInterface $created_at
 */
final class AuditLogModel extends Model
{
    protected $table = 'admin_audit_log';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
