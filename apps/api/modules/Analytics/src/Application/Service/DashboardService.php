<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use EruoFood\Analytics\Application\DTO\ChartSeries;
use EruoFood\Analytics\Application\DTO\DashboardView;
use EruoFood\Analytics\Domain\Enum\DashboardType;
use EruoFood\Analytics\Domain\Enum\Granularity;
use EruoFood\Analytics\Domain\Metric\Kpi;
use EruoFood\Analytics\Domain\Metric\MetricRepository;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * The Dashboard Service — assembles the named dashboards (executive, operations,
 * finance, restaurant, vendor, admin) from the KPI engine and the metric store:
 * a row of KPI cards, chart time-series and dimension breakdowns for the range.
 */
final readonly class DashboardService
{
    public function __construct(
        private KpiEngine $kpis,
        private MetricRepository $metrics,
    ) {
    }

    public function build(DashboardType $type, DateRange $range, ?string $scopeId = null): DashboardView
    {
        return match ($type) {
            DashboardType::Executive, DashboardType::Admin => $this->executive($type, $range),
            DashboardType::Finance => $this->finance($range),
            DashboardType::Operations => $this->operations($range),
            DashboardType::Vendor, DashboardType::Restaurant => $this->vendor($type, $range, $scopeId ?? ''),
        };
    }

    private function executive(DashboardType $type, DateRange $range): DashboardView
    {
        $kpis = [
            $this->kpis->kpi('revenue', 'Revenue', 'money', $range),
            $this->kpis->kpi('orders', 'Orders', 'count', $range),
            $this->kpis->kpi('customers', 'New customers', 'count', $range),
            $this->kpis->kpi('ai_tokens', 'AI tokens', 'tokens', $range),
        ];
        $charts = [
            $this->series('revenue', 'Revenue', 'money', $range, true),
            $this->series('orders', 'Orders', 'count', $range, false),
        ];
        $breakdowns = ['revenue_by_provider' => $this->metrics->breakdown('revenue', 'provider', $range, true)];

        return $this->view($type, $range, $kpis, $charts, $breakdowns);
    }

    private function finance(DateRange $range): DashboardView
    {
        $kpis = [
            $this->kpis->kpi('revenue', 'Revenue', 'money', $range),
            $this->kpis->kpi('refunds', 'Refunds', 'money', $range),
            $this->kpis->kpi('settlements', 'Settlements', 'money', $range),
            $this->kpis->kpi('failed_payments', 'Failed payments', 'count', $range),
        ];
        $charts = [
            $this->series('revenue', 'Revenue', 'money', $range, true),
            $this->series('refunds', 'Refunds', 'money', $range, true),
        ];
        $breakdowns = ['settlements_by_payee' => $this->metrics->breakdown('settlements', 'payee_type', $range, true)];

        return $this->view(DashboardType::Finance, $range, $kpis, $charts, $breakdowns);
    }

    private function operations(DateRange $range): DashboardView
    {
        $kpis = [
            $this->kpis->kpi('notifications', 'Notifications', 'count', $range),
            $this->kpis->kpi('ai_tokens', 'AI tokens', 'tokens', $range),
            $this->kpis->kpi('failed_payments', 'Failed payments', 'count', $range),
        ];
        $charts = [
            $this->series('notifications', 'Notifications', 'count', $range, false),
            $this->series('ai_tokens', 'AI tokens', 'tokens', $range, true),
        ];
        $breakdowns = [
            'notifications_by_channel' => $this->metrics->breakdown('notifications', 'channel', $range, false),
            'ai_tokens_by_provider' => $this->metrics->breakdown('ai_tokens', 'provider', $range, true),
        ];

        return $this->view(DashboardType::Operations, $range, $kpis, $charts, $breakdowns);
    }

    private function vendor(DashboardType $type, DateRange $range, string $vendorId): DashboardView
    {
        $kpis = [
            $this->kpis->scopedKpi('orders', 'My orders', 'count', 'vendor_id', $vendorId, $range),
        ];

        return $this->view($type, $range, $kpis, [], ['orders_by_vendor' => $this->metrics->breakdown('orders', 'vendor_id', $range, false)]);
    }

    private function series(string $metric, string $label, string $unit, DateRange $range, bool $useSum): ChartSeries
    {
        return new ChartSeries($metric, $label, $unit, $this->metrics->series($metric, $range, Granularity::Day, $useSum));
    }

    /**
     * @param list<Kpi> $kpis
     * @param list<ChartSeries> $charts
     * @param array<string, array<string, int>> $breakdowns
     */
    private function view(DashboardType $type, DateRange $range, array $kpis, array $charts, array $breakdowns): DashboardView
    {
        return new DashboardView($type->value, $range->fromDate(), $range->toDate(), $kpis, $charts, $breakdowns);
    }
}
