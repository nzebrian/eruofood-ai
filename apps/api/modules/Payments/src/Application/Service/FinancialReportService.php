<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Application\DTO\FinancialReport;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Payment\PaymentRepository;

/** Aggregated financial figures for the admin dashboard / tax-ready reporting. */
final readonly class FinancialReportService
{
    public function __construct(
        private PaymentRepository $payments,
        private LedgerService $ledger,
        private string $currency,
    ) {
    }

    public function overview(): FinancialReport
    {
        $captured = $this->payments->capturedTotals();
        $commission = $this->ledger->balanceOf(LedgerAccount::Commission);
        $fees = $this->ledger->balanceOf(LedgerAccount::Fees);
        $refunded = $this->ledger->balanceOf(LedgerAccount::Refunds);
        $net = $captured['gross_minor'] - $commission - $fees - $refunded;

        return new FinancialReport(
            capturedCount: $captured['count'],
            grossMinor: $captured['gross_minor'],
            commissionMinor: $commission,
            feesMinor: $fees,
            refundedMinor: $refunded,
            netMinor: $net,
            currency: $this->currency,
        );
    }
}
