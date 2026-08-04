<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\Port\CostCalculator;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/** Returns a fixed cost so gateway tests can assert cost attribution. */
final class FixedCostCalculator implements CostCalculator
{
    public function __construct(private readonly float $cost = 0.01)
    {
    }

    public function costFor(AiProviderName $provider, string $model, TokenUsage $tokens): float
    {
        return $this->cost;
    }
}
