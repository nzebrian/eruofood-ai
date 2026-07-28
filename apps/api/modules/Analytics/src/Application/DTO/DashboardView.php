<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\DTO;

use EruoFood\Analytics\Domain\Metric\Kpi;

/**
 * An assembled dashboard: its type, the date range, a row of KPI cards, a set of
 * chart series, and dimension breakdowns (each a label => value map).
 */
final readonly class DashboardView
{
    /**
     * @param list<Kpi> $kpis
     * @param list<ChartSeries> $charts
     * @param array<string, array<string, int>> $breakdowns
     */
    public function __construct(
        public string $type,
        public string $from,
        public string $to,
        public array $kpis,
        public array $charts,
        public array $breakdowns,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'range' => ['from' => $this->from, 'to' => $this->to],
            'kpis' => array_map(static fn (Kpi $k): array => $k->toArray(), $this->kpis),
            'charts' => array_map(static fn (ChartSeries $c): array => $c->toArray(), $this->charts),
            'breakdowns' => $this->breakdowns,
        ];
    }
}
