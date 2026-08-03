<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\Granularity;
use EruoFood\Analytics\Domain\Metric\MetricRepository;
use EruoFood\Analytics\Domain\ValueObject\DataPoint;
use EruoFood\Analytics\Domain\ValueObject\DateRange;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model\MetricBucketModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Reads/writes the pre-aggregated daily metric buckets. Writes are read-modify-
 * write increments (single-writer / synchronous collection); reads sum the
 * relevant buckets and roll daily buckets up to the requested granularity.
 */
final class EloquentMetricRepository implements MetricRepository
{
    public function increment(
        string $metric,
        string $category,
        DateTimeImmutable $day,
        int $value,
        ?string $dimensionKey,
        ?string $dimensionValue,
    ): void {
        $query = MetricBucketModel::query()
            ->where('metric', $metric)
            ->whereDate('bucket_date', $day->format('Y-m-d'));
        $dimensionKey === null ? $query->whereNull('dimension_key') : $query->where('dimension_key', $dimensionKey);
        $dimensionValue === null ? $query->whereNull('dimension_value') : $query->where('dimension_value', $dimensionValue);

        $row = $query->first() ?? new MetricBucketModel();
        if (! $row->exists) {
            $row->id = (string) Str::orderedUuid();
            $row->metric = $metric;
            $row->category = $category;
            $row->bucket_date = $day->format('Y-m-d');
            $row->dimension_key = $dimensionKey;
            $row->dimension_value = $dimensionValue;
            $row->count = 0;
            $row->sum_value = 0;
        }
        $row->count = (int) $row->count + 1;
        $row->sum_value = (int) $row->sum_value + $value;
        $row->save();
    }

    public function totalCount(string $metric, DateRange $range, ?string $dimensionKey = null, ?string $dimensionValue = null): int
    {
        return (int) $this->scoped($metric, $range, $dimensionKey, $dimensionValue)->sum('count');
    }

    public function totalSum(string $metric, DateRange $range, ?string $dimensionKey = null, ?string $dimensionValue = null): int
    {
        return (int) $this->scoped($metric, $range, $dimensionKey, $dimensionValue)->sum('sum_value');
    }

    public function series(string $metric, DateRange $range, Granularity $granularity, bool $useSum): array
    {
        $rows = MetricBucketModel::query()
            ->where('metric', $metric)
            ->whereNull('dimension_key')
            ->whereBetween('bucket_date', [$range->from, $range->to])
            ->orderBy('bucket_date')
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $date = new DateTimeImmutable((string) $row->bucket_date);
            $key = $granularity->bucketOf($date);
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) ($useSum ? $row->sum_value : $row->count);
        }

        $points = [];
        foreach ($buckets as $bucket => $value) {
            $points[] = new DataPoint((string) $bucket, $value);
        }

        return $points;
    }

    public function breakdown(string $metric, string $dimensionKey, DateRange $range, bool $useSum): array
    {
        $rows = MetricBucketModel::query()
            ->where('metric', $metric)
            ->where('dimension_key', $dimensionKey)
            ->whereBetween('bucket_date', [$range->from, $range->to])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $value = (string) $row->dimension_value;
            $out[$value] = ($out[$value] ?? 0) + (int) ($useSum ? $row->sum_value : $row->count);
        }
        arsort($out);

        return $out;
    }

    /**
     * @return Builder<MetricBucketModel>
     */
    private function scoped(string $metric, DateRange $range, ?string $dimensionKey, ?string $dimensionValue): Builder
    {
        $query = MetricBucketModel::query()
            ->where('metric', $metric)
            ->whereBetween('bucket_date', [$range->from, $range->to]);
        $dimensionKey === null ? $query->whereNull('dimension_key') : $query->where('dimension_key', $dimensionKey);
        if ($dimensionValue !== null) {
            $query->where('dimension_value', $dimensionValue);
        }

        return $query;
    }
}
