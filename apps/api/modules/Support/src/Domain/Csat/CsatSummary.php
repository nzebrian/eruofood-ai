<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Csat;

/** Aggregate CSAT stats over a window, for the satisfaction dashboard. */
final readonly class CsatSummary
{
    /**
     * @param array<int, int> $distribution score (1-5) => count
     */
    public function __construct(
        public int $responses,
        public float $average,
        public array $distribution,
        public float $satisfactionRate,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'responses' => $this->responses,
            'average' => round($this->average, 2),
            'distribution' => $this->distribution,
            'satisfaction_rate' => round($this->satisfactionRate, 4),
        ];
    }
}
