<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller\Admin;

use DateTimeImmutable;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SettlementService;
use EruoFood\Payments\Domain\Settlement\Payout;
use EruoFood\Payments\Domain\Settlement\Settlement;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin settlement runs and payouts (RBAC). */
final readonly class SettlementAdminController
{
    use RespondsWithData;

    public function __construct(
        private SettlementService $settlements,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function settle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payee_type' => ['required', 'in:vendor,restaurant,driver'],
            'payee_id' => ['required', 'uuid'],
            'gross_minor' => ['required', 'integer', 'min:1'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'bank' => ['nullable', 'array'],
            'bank.account_name' => ['required_with:bank', 'string'],
            'bank.account_number' => ['required_with:bank', 'string'],
            'bank.bank_code' => ['required_with:bank', 'string'],
        ]);

        $bank = isset($data['bank']) && is_array($data['bank']) ? BankAccount::fromArray($data['bank']) : null;
        $settlement = $this->settlements->settle(
            (string) $data['payee_type'],
            (string) $data['payee_id'],
            (int) $data['gross_minor'],
            new DateTimeImmutable((string) $data['period_start']),
            new DateTimeImmutable((string) $data['period_end']),
            $bank,
        );

        return $this->data($this->presenter->settlement($settlement), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->settlements->all((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Settlement $s): array => $this->presenter->settlement($s));
    }

    public function payouts(Request $request): JsonResponse
    {
        $page = $this->settlements->payouts((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Payout $p): array => $this->presenter->payout($p));
    }
}
