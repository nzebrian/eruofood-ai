<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Usage;

/** Aggregated usage/cost totals for a user (or the whole platform). */
final readonly class UsageSummary
{
    public function __construct(
        public int $requests,
        public int $inputTokens,
        public int $outputTokens,
        public float $costUsd,
        public int $cachedRequests,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
            'cached_requests' => $this->cachedRequests,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'cost_usd' => round($this->costUsd, 6),
        ];
    }
}
