<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\DTO;

/** A store's (or the platform's) sales summary for a dashboard. */
final readonly class SalesReport
{
    public function __construct(
        public int $orders,
        public int $revenueMinor,
        public string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orders' => $this->orders,
            'revenue_minor' => $this->revenueMinor,
            'currency' => $this->currency,
        ];
    }
}
