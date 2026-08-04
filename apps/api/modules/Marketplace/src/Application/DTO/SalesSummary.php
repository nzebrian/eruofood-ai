<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\DTO;

/** Aggregated sales figures for a vendor's dashboard. */
final readonly class SalesSummary
{
    public function __construct(
        public int $totalOrders,
        public int $deliveredOrders,
        public int $pendingOrders,
        public int $revenueMinor,
        public string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_orders' => $this->totalOrders,
            'delivered_orders' => $this->deliveredOrders,
            'pending_orders' => $this->pendingOrders,
            'revenue_minor' => $this->revenueMinor,
            'currency' => $this->currency,
        ];
    }
}
