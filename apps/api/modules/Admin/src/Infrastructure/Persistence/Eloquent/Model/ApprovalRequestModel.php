<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
