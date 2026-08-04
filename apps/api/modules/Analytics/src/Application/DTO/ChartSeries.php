<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\DTO;

use EruoFood\Analytics\Domain\ValueObject\DataPoint;

/** A named metric time series for a chart. */
final readonly class ChartSeries
{
    /**
     * @param list<DataPoint> $points
     */
    public function __construct(
        public string $metric,
        public string $label,
        public string $unit,
        public array $points,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'label' => $this->label,
            'unit' => $this->unit,
            'points' => array_map(static fn (DataPoint $p): array => $p->toArray(), $this->points),
        ];
    }
}
