<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $kind
 * @property array<array-key, mixed> $details
 * @property string $status
 * @property string|null $decided_by
 * @property string|null $note
 * @property DateTimeInterface $submitted_at
 * @property DateTimeInterface|null $decided_at
 */
final class ApprovalRequestModel extends Model
{
    protected $table = 'admin_approval_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
