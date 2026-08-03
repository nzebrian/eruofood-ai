<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $trigger
 * @property array<array-key, mixed> $conditions
 * @property array<array-key, mixed> $actions
 * @property bool $enabled
 * @property int $sort_order
 */
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
