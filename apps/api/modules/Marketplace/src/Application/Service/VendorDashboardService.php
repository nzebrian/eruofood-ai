<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Application\DTO\SalesSummary;
use EruoFood\Marketplace\Domain\Order\OrderRepository;

/** The vendor sales dashboard: order counts and revenue for an owned vendor. */
final readonly class VendorDashboardService
{
    public function __construct(
        private OrderRepository $orders,
        private VendorService $vendors,
        private string $currency,
    ) {
    }

    public function salesSummary(string $userId, bool $isAdmin, string $vendorId): SalesSummary
    {
        $this->vendors->manageable($userId, $isAdmin, $vendorId);
        $s = $this->orders->salesSummary($vendorId);

        return new SalesSummary(
            totalOrders: $s['total'],
            deliveredOrders: $s['delivered'],
            pendingOrders: $s['pending'],
            revenueMinor: $s['revenue_minor'],
            currency: $this->currency,
        );
    }
}
