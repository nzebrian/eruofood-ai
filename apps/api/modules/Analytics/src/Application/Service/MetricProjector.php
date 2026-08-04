<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Metric\MetricRepository;

/**
 * The event-processing pipeline's write side: turns a single collected fact into
 * metric increments. It writes a total daily bucket (no dimension) plus one
 * bucket per dimension value, so dashboards can read both overall totals and
 * per-dimension breakdowns without scanning raw events.
 */
final readonly class MetricProjector
{
    public function __construct(private MetricRepository $metrics)
    {
    }

    /**
     * @param array<string, string> $dimensions
     */
    public function project(string $metric, string $category, DateTimeImmutable $day, int $valueForSum, array $dimensions): void
    {
        $this->metrics->increment($metric, $category, $day, $valueForSum, null, null);
        foreach ($dimensions as $key => $value) {
            $this->metrics->increment($metric, $category, $day, $valueForSum, $key, $value);
        }
    }
}
