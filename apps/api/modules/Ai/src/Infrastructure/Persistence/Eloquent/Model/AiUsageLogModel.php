<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $feature
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $total_tokens
 * @property float $cost_usd
 * @property bool $cached
 * @property int $latency_ms
 * @property bool $success
 * @property string|null $error_code
 * @property \Illuminate\Support\Carbon $created_at
 */
final class AiUsageLogModel extends Model
{
    protected $table = 'ai_usage_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'float',
            'cached' => 'boolean',
            'latency_ms' => 'integer',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
