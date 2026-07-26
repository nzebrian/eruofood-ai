<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $feature
 * @property int $version
 * @property string $name
 * @property string $system_template
 * @property string $user_template
 * @property string|null $model
 * @property list<string> $variables
 * @property bool $active
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PromptTemplateModel extends Model
{
    protected $table = 'ai_prompt_templates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'variables' => 'array',
            'active' => 'boolean',
        ];
    }
}
