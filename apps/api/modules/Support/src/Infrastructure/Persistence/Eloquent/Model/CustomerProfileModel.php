<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string|null $display_name
 * @property string|null $email
 * @property string $segment
 * @property int $order_count
 * @property int $total_spent_minor
 * @property int $ticket_count
 * @property array<array-key, mixed> $tags
 * @property string|null $notes
 * @property string|null $insight
 * @property DateTimeInterface|null $insight_generated_at
 * @property DateTimeInterface|null $last_interaction_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class CustomerProfileModel extends Model
{
    protected $table = 'support_customer_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'order_count' => 'integer',
            'total_spent_minor' => 'integer',
            'ticket_count' => 'integer',
            'insight_generated_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
