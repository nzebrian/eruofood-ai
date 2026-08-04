<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ref
 * @property string $requester_id
 * @property string $subject
 * @property string $category
 * @property string $channel
 * @property string $status
 * @property string $priority
 * @property int $priority_weight
 * @property string|null $assignee_id
 * @property string|null $sla_policy_id
 * @property DateTimeInterface|null $first_response_due_at
 * @property DateTimeInterface|null $resolution_due_at
 * @property DateTimeInterface|null $first_responded_at
 * @property DateTimeInterface|null $resolved_at
 * @property DateTimeInterface|null $closed_at
 * @property array<array-key, mixed> $tags
 * @property string|null $related_order_id
 * @property string|null $merged_into_id
 * @property int|null $csat_score
 * @property array<array-key, mixed> $messages
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class TicketModel extends Model
{
    protected $table = 'support_tickets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'messages' => 'array',
            'priority_weight' => 'integer',
            'csat_score' => 'integer',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
