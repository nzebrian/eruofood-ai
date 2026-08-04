<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Metric;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\Granularity;
use EruoFood\Analytics\Domain\ValueObject\DataPoint;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * The rolled-up metric store. The projection pipeline increments daily buckets
 * (optionally per dimension value); dashboards and reports read totals, time
 * series and breakdowns back out. Storing pre-aggregated daily buckets keeps
 * dashboard queries fast regardless of raw event volume.
 */
interface MetricRepository
{
    /**
     * Increment a metric's daily bucket: add 1 to count and $value to the sum,
     * for the given (metric, day, dimensionKey, dimensionValue).
     */
    public function increment(
        string $metric,
        string $category,
        DateTimeImmutable $day,
        int $value,
        ?string $dimensionKey,
        ?string $dimensionValue,
    ): void;

    /** Total count for a metric over a range (optionally filtered by a dimension value). */
    public function totalCount(string $metric, DateRange $range, ?string $dimensionKey = null, ?string $dimensionValue = null): int;

    /** Total sum for a metric over a range. */
    public function totalSum(string $metric, DateRange $range, ?string $dimensionKey = null, ?string $dimensionValue = null): int;

    /**
     * A time series for a metric at a granularity.
     *
     * @return list<DataPoint>
     */
    public function series(string $metric, DateRange $range, Granularity $granularity, bool $useSum): array;

    /**
     * A breakdown of a metric by a dimension over a range (value => total).
     *
     * @return array<string, int>
     */
    public function breakdown(string $metric, string $dimensionKey, DateRange $range, bool $useSum): array;
}
