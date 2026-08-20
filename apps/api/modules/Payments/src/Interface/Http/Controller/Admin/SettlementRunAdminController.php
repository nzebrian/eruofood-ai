<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller\Admin;

use DateTimeImmutable;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\PayoutAttempt;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The back-office settlement surface.
 *
 * ## Not one endpoint, and not one permission
 *
 * Reading, computing, approving, executing, retrying, reconciling and reversing
 * are seven separate routes behind five separate permissions, declared in
 * `routes.php`. The controller does not check permissions itself — that is the
 * middleware's job and duplicating it here would create a second place for the
 * two to disagree.
 *
 * ## No endpoint here accepts an amount
 *
 * `compute` takes a merchant and a window. `execute` takes an id. The figure is
 * derived from accruals every time, and there is no request field anywhere on
 * this controller that could change what a merchant is paid. That is the
 * difference between it and the endpoint it replaces.
 */
final readonly class SettlementRunAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private SettlementRunService $settlements,
        private SettlementReconciliationService $reconciliation,
        private SettlementRunRepository $runs,
        private PayoutAttemptRepository $attempts,
        private PayableAccrualRepository $accruals,
        private PaymentsPresenter $presenter,
    ) {
    }

    // ---- Read (finance.read) ------------------------------------------------

    public function payables(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->integer('limit', 50)));

        return $this->data(['merchants' => $this->accruals->merchantsWithPayable($limit)]);
    }

    public function index(Request $request): JsonResponse
    {
        $state = $request->filled('state')
            ? SettlementRunState::tryFrom((string) $request->string('state'))
            : null;

        $page = $this->runs->all($state, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (SettlementRun $r): array => $this->presenter->settlementRun($r));
    }

    public function show(string $id): JsonResponse
    {
        $run = $this->settlements->runOrFail($id);

        return $this->data([
            'run' => $this->presenter->settlementRun($run),
            'attempts' => array_map(
                fn (PayoutAttempt $a): array => $this->presenter->payoutAttempt($a),
                $this->attempts->forRun($id),
            ),
            'lines' => count($this->runs->linesFor($id)),
        ]);
    }

    public function attempts(Request $request): JsonResponse
    {
        $page = $this->attempts->all((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (PayoutAttempt $a): array => $this->presenter->payoutAttempt($a));
    }

    /**
     * The one screen an operator checks before going home.
     *
     * Deliberately leads with what is *stuck* rather than what succeeded: a
     * settlement dashboard whose headline number is "paid today" hides the two
     * runs nobody can account for.
     */
    public function health(): JsonResponse
    {
        return $this->data([
            'runs_by_state' => $this->runs->countsByState(),
            'attempts_by_state' => $this->attempts->countsByState(),
            'accrual_totals' => $this->accruals->totals(),
            'awaiting_reconciliation' => count($this->runs->awaitingReconciliation(100)),
            'attempts_needing_reconciliation' => count($this->attempts->needingReconciliation(100)),
        ]);
    }

    // ---- Compute and approve (finance.settle) -------------------------------

    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_type' => ['required', 'in:vendor,restaurant,driver'],
            'merchant_id' => ['required', 'uuid'],
            'window_start' => ['required', 'date'],
            'window_end' => ['required', 'date', 'after:window_start'],
            // Note what is absent: there is no amount field, and adding one
            // would reintroduce the defect this milestone exists to fix.
        ]);

        $run = $this->settlements->computeDraft(
            $this->currentUserId($request),
            (string) $data['merchant_type'],
            (string) $data['merchant_id'],
            new DateTimeImmutable((string) $data['window_start']),
            new DateTimeImmutable((string) $data['window_end']),
            $this->idempotencyKey($request),
        );

        return $this->data($this->presenter->settlementRun($run), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $run = $this->settlements->approve($this->currentUserId($request), $id, $data['reason'] ?? null);

        return $this->data($this->presenter->settlementRun($run));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $run = $this->settlements->cancel($this->currentUserId($request), $id, $data['reason'] ?? null);

        return $this->data($this->presenter->settlementRun($run));
    }

    // ---- Execute and retry (finance.payout) ---------------------------------

    public function execute(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'bank' => ['nullable', 'array'],
            'bank.account_name' => ['required_with:bank', 'string', 'max:140'],
            'bank.account_number' => ['required_with:bank', 'string', 'max:32'],
            'bank.bank_code' => ['required_with:bank', 'string', 'max:16'],
        ]);

        // No bank account credits the merchant's wallet — an internal movement
        // that commits atomically and cannot end in an unknown state.
        $bank = isset($data['bank']) && is_array($data['bank']) ? BankAccount::fromArray($data['bank']) : null;

        $run = $this->settlements->execute($this->currentUserId($request), $id, $bank);

        return $this->data($this->presenter->settlementRun($run));
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $run = $this->settlements->retry($this->currentUserId($request), $id, $data['reason'] ?? null);

        return $this->data($this->presenter->settlementRun($run));
    }

    // ---- Reconcile (finance.reconcile) --------------------------------------

    public function reconcile(string $id): JsonResponse
    {
        return $this->data($this->presenter->settlementRun($this->reconciliation->reconcileRun($id)));
    }

    // ---- Reverse (finance.reverse) ------------------------------------------

    public function reverse(Request $request, string $id): JsonResponse
    {
        // Required, not optional. A reversal without a stated reason is the one
        // audit entry a reviewer will always come back to.
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $run = $this->settlements->reverse($this->currentUserId($request), $id, (string) $data['reason']);

        return $this->data($this->presenter->settlementRun($run));
    }

    /**
     * The caller's idempotency key, when they sent one.
     *
     * Optional on compute — a draft moves nothing, so a duplicate is a wasted
     * call rather than a duplicate payment — and the live-window unique index
     * refuses the second one regardless.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
