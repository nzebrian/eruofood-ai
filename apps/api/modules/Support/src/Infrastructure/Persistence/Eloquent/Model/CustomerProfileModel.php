<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
