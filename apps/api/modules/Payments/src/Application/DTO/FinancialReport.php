<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

/** Aggregated financial figures for the admin dashboard / tax-ready reporting. */
final readonly class FinancialReport
{
    public function __construct(
        public int $capturedCount,
        public int $grossMinor,
        public int $commissionMinor,
        public int $feesMinor,
        public int $refundedMinor,
        public int $netMinor,
        public string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'captured_count' => $this->capturedCount,
            'gross_minor' => $this->grossMinor,
            'commission_minor' => $this->commissionMinor,
            'fees_minor' => $this->feesMinor,
            'refunded_minor' => $this->refundedMinor,
            'net_minor' => $this->netMinor,
            'currency' => $this->currency,
        ];
    }
}
