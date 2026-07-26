<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Cost;

use EruoFood\Ai\Application\Port\CostCalculator;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/**
 * Computes a USD cost from a pricing table keyed by "provider/model" (USD per
 * 1M tokens for input and output), falling back to a `default` rate for models
 * not in the table. Drives the AI Cost Tracking figures on the usage ledger.
 */
final readonly class TableCostCalculator implements CostCalculator
{
    private const MILLION = 1_000_000;

    /** @param array<string, array{input: float, output: float}> $pricing */
    public function __construct(private array $pricing)
    {
    }

    public function costFor(AiProviderName $provider, string $model, TokenUsage $tokens): float
    {
        $rate = $this->pricing[$provider->value.'/'.$model]
            ?? $this->pricing['default']
            ?? ['input' => 0.0, 'output' => 0.0];

        $input = ($tokens->inputTokens / self::MILLION) * $rate['input'];
        $output = ($tokens->outputTokens / self::MILLION) * $rate['output'];

        return round($input + $output, 8);
    }
}
