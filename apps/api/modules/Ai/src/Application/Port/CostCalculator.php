<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Port;

use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/**
 * Attributes a USD cost to a completion from its token usage and the pricing
 * table, powering AI Cost Tracking on the usage ledger.
 */
interface CostCalculator
{
    public function costFor(AiProviderName $provider, string $model, TokenUsage $tokens): float;
}
