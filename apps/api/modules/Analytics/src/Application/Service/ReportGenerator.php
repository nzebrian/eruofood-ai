<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\Granularity;
use EruoFood\Analytics\Domain\Metric\MetricRepository;
use EruoFood\Analytics\Domain\Report\Report;
use EruoFood\Analytics\Domain\Report\ReportRepository;
use EruoFood\Analytics\Domain\ValueObject\DataPoint;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * The Report Generator — builds a tabular {@see Report} for a known report key
 * over a date range from the metric store, and persists it. Reports are the
 * exportable outputs behind the export and scheduled-report flows.
 */
final readonly class ReportGenerator
{
    /** @var array<string, string> report key => human title */
    private const TITLES = [
        'revenue' => 'Revenue report',
        'sales_trend' => 'Sales trend',
        'customer_growth' => 'Customer growth',
        'financial' => 'Financial summary',
        'ai_usage' => 'AI usage',
        'refunds' => 'Refund report',
        'settlements' => 'Settlement report',
        'vendor_performance' => 'Vendor performance',
        'product_performance' => 'Product performance',
    ];

    public function __construct(
        private ReportRepository $reports,
        private MetricRepository $metrics,
    ) {
    }

    /** @return list<string> the available report keys */
    public function catalogue(): array
    {
        return array_keys(self::TITLES);
    }

    public function generate(string $key, DateRange $range): Report
    {
        $now = new DateTimeImmutable();
        [$columns, $rows] = $this->build($key, $range);

        $report = Report::ready(
            $this->reports->nextIdentity(),
            $key,
            self::TITLES[$key] ?? ucfirst(str_replace('_', ' ', $key)),
            $range,
            $columns,
            $rows,
            $now,
        );
        $this->reports->save($report);

        return $report;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|int|float>>}
     */
    private function build(string $key, DateRange $range): array
    {
        return match ($key) {
            'sales_trend' => [['Date', 'Orders'], $this->timeSeriesRows('orders', $range, false)],
            'customer_growth' => [['Date', 'New customers'], $this->timeSeriesRows('customers', $range, false)],
            'refunds' => [['Date', 'Refunds (minor)'], $this->timeSeriesRows('refunds', $range, true)],
            'ai_usage' => [['Provider', 'Tokens'], $this->breakdownRows('ai_tokens', 'provider', $range, true)],
            'settlements' => [['Payee type', 'Amount (minor)'], $this->breakdownRows('settlements', 'payee_type', $range, true)],
            'vendor_performance' => [['Vendor', 'Orders'], $this->breakdownRows('orders', 'vendor_id', $range, false)],
            'product_performance' => [['Store', 'Products'], $this->breakdownRows('products', 'store_id', $range, false)],
            'financial' => [['Metric', 'Amount (minor)'], $this->financialRows($range)],
            default => [['Date', 'Revenue (minor)'], $this->timeSeriesRows('revenue', $range, true)],
        };
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function timeSeriesRows(string $metric, DateRange $range, bool $useSum): array
    {
        return array_map(
            static fn (DataPoint $p): array => [$p->bucket, $p->value],
            $this->metrics->series($metric, $range, Granularity::Day, $useSum),
        );
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function breakdownRows(string $metric, string $dimensionKey, DateRange $range, bool $useSum): array
    {
        $rows = [];
        foreach ($this->metrics->breakdown($metric, $dimensionKey, $range, $useSum) as $label => $value) {
            $rows[] = [$label, $value];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function financialRows(DateRange $range): array
    {
        $revenue = $this->metrics->totalSum('revenue', $range);
        $refunds = $this->metrics->totalSum('refunds', $range);
        $settlements = $this->metrics->totalSum('settlements', $range);
        $failed = $this->metrics->totalCount('failed_payments', $range);

        return [
            ['Revenue', $revenue],
            ['Refunds', $refunds],
            ['Settlements', $settlements],
            ['Net (revenue - refunds)', $revenue - $refunds],
            ['Failed payments', $failed],
        ];
    }
}
