<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $requester_id
 * @property string $subject
 * @property string $category
 * @property string $status
 * @property string $priority
 * @property int $priority_weight
 * @property string|null $assignee_id
 * @property array<array-key, mixed> $messages
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class TicketModel extends Model
{
    protected $table = 'admin_tickets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
