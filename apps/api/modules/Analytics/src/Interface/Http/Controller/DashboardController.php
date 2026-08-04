<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Interface\Http\Controller;

use EruoFood\Analytics\Application\Service\DashboardService;
use EruoFood\Analytics\Application\Service\KpiEngine;
use EruoFood\Analytics\Domain\Enum\DashboardType;
use EruoFood\Analytics\Domain\Exception\AnalyticsNotFound;
use EruoFood\Analytics\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Analytics\Interface\Http\Concerns\ResolvesDateRange;
use EruoFood\Analytics\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Assembled analytics dashboards and the KPI row. */
final readonly class DashboardController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use ResolvesDateRange;

    public function __construct(
        private DashboardService $dashboards,
        private KpiEngine $kpis,
        private int $defaultDays,
    ) {
    }

    /** Admin dashboards: executive, operations, finance, admin. */
    public function show(Request $request, string $type): JsonResponse
    {
        $dashboard = DashboardType::tryFrom($type) ?? throw AnalyticsNotFound::of('dashboard', $type);
        $view = $this->dashboards->build($dashboard, $this->resolveRange($request, $this->defaultDays));

        return $this->data($view->toArray());
    }

    /** A vendor/restaurant owner's scoped dashboard. */
    public function scoped(Request $request, string $type): JsonResponse
    {
        $dashboard = DashboardType::tryFrom($type) ?? throw AnalyticsNotFound::of('dashboard', $type);
        $scopeId = ((string) $request->string('vendor_id')) ?: $this->currentUserId($request);
        $view = $this->dashboards->build($dashboard, $this->resolveRange($request, $this->defaultDays), $scopeId);

        return $this->data($view->toArray());
    }

    /** A standalone KPI row for a range. */
    public function kpis(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request, $this->defaultDays);
        $cards = [
            $this->kpis->kpi('revenue', 'Revenue', 'money', $range),
            $this->kpis->kpi('orders', 'Orders', 'count', $range),
            $this->kpis->kpi('customers', 'New customers', 'count', $range),
            $this->kpis->kpi('ai_tokens', 'AI tokens', 'tokens', $range),
            $this->kpis->kpi('refunds', 'Refunds', 'money', $range),
            $this->kpis->kpi('notifications', 'Notifications', 'count', $range),
        ];

        return $this->data(['kpis' => array_map(static fn ($k): array => $k->toArray(), $cards)]);
    }
}
