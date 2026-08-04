<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Application\DTO\SalesReport;
use EruoFood\Commerce\Domain\Order\OrderRepository;

/** Aggregated figures for the seller & admin dashboards / sales reporting. */
final readonly class AdminDashboardService
{
    public function __construct(
        private OrderRepository $orders,
        private string $currency,
    ) {
    }

    public function salesForStore(string $storeId): SalesReport
    {
        $summary = $this->orders->salesSummaryForStore($storeId);

        return new SalesReport($summary['orders'], $summary['revenue_minor'], $this->currency);
    }
}
