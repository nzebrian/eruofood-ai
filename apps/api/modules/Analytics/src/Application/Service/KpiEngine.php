<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use EruoFood\Analytics\Domain\Metric\Kpi;
use EruoFood\Analytics\Domain\Metric\MetricRepository;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * Computes KPIs from the metric store, each with its change versus the previous
 * comparable period (the same number of days immediately before the range), so
 * the UI can show trend arrows.
 */
final readonly class KpiEngine
{
    public function __construct(private MetricRepository $metrics)
    {
    }

    public function kpi(string $metric, string $label, string $unit, DateRange $range): Kpi
    {
        $useSum = $unit === 'money' || $unit === 'tokens';
        $current = $useSum ? $this->metrics->totalSum($metric, $range) : $this->metrics->totalCount($metric, $range);
        $previous = $useSum
            ? $this->metrics->totalSum($metric, $this->previous($range))
            : $this->metrics->totalCount($metric, $this->previous($range));

        return Kpi::withDelta($metric, $label, $current, $previous, $unit);
    }

    public function scopedKpi(string $metric, string $label, string $unit, string $dimKey, string $dimValue, DateRange $range): Kpi
    {
        $useSum = $unit === 'money' || $unit === 'tokens';
        $current = $useSum
            ? $this->metrics->totalSum($metric, $range, $dimKey, $dimValue)
            : $this->metrics->totalCount($metric, $range, $dimKey, $dimValue);
        $previous = $useSum
            ? $this->metrics->totalSum($metric, $this->previous($range), $dimKey, $dimValue)
            : $this->metrics->totalCount($metric, $this->previous($range), $dimKey, $dimValue);

        return Kpi::withDelta($metric, $label, $current, $previous, $unit);
    }

    private function previous(DateRange $range): DateRange
    {
        $days = $range->days();
        $prevTo = $range->from->modify('-1 day')->setTime(23, 59, 59);
        $prevFrom = $prevTo->modify(sprintf('-%d days', max(0, $days - 1)))->setTime(0, 0, 0);

        return new DateRange($prevFrom, $prevTo);
    }
}
