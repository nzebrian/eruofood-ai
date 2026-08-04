<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller\Admin;

use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Service\FinancialReportService;
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\RefundService;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin transactions dashboard, refunds, financial report and provider management. */
final readonly class FinanceAdminController
{
    use RespondsWithData;

    public function __construct(
        private PaymentService $payments,
        private RefundService $refunds,
        private FinancialReportService $report,
        private PaymentGatewayFactory $gateways,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function payments(Request $request): JsonResponse
    {
        $status = $request->filled('status') ? PaymentStatus::tryFrom((string) $request->string('status')) : null;
        $page = $this->payments->all($status, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Payment $p): array => $this->presenter->payment($p));
    }

    public function refunds(Request $request): JsonResponse
    {
        $page = $this->refunds->all((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Refund $r): array => $this->presenter->refund($r));
    }

    public function report(): JsonResponse
    {
        return $this->data($this->report->overview()->toArray());
    }

    public function providers(): JsonResponse
    {
        return $this->data([
            'providers' => array_map(static fn (PaymentProvider $p): string => $p->value, $this->gateways->available()),
        ]);
    }
}
