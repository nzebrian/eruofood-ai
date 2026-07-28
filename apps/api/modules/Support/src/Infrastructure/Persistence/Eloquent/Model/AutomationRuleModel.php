<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class AutomationRuleModel extends Model
{
    protected $table = 'support_automation_rules';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
