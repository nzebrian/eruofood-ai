<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\LedgerService;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\PayoutAttemptState;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementLineModel;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MockGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

const VENDOR = '44444444-4444-4444-8444-444444444444';
const APPROVER = '55555555-5555-4555-8555-555555555555';
const EXECUTOR = '66666666-6666-4666-8666-666666666666';

beforeEach(function (): void {
    MockGateway::reset();
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'true',
        'flags.overrides.settlement.compute' => 'true',
        'flags.overrides.settlement.execute' => 'true',
        'flags.overrides.settlement.reconcile' => 'true',
    ]);
});

/** Capture a payment and accrue it, returning the net owed. */
function accrue(object $test, string $orderId, int $grossMinor, string $email): int
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $grossMinor,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated();

    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', VENDOR));

    return app(PayableAccrualRepository::class)->findEarningForOrder($orderId)->net()->minorUnits;
}

function runs(): SettlementRunService
{
    return app(SettlementRunService::class);
}

function window(): array
{
    return [new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')];
}

it('computes a draft from accruals with no amount supplied anywhere', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000001', 1_000_000, 'draft1@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);

    expect($run->state())->toBe(SettlementRunState::Draft)
        ->and($run->net()->minorUnits)->toBe($net)
        ->and($run->gross()->minorUnits)->toBe(1_000_000)
        ->and($run->settlementReference())->toStartWith('STL-');

    // The accrual is reserved by the draft, so it cannot land on a second run.
    expect(SettlementLineModel::query()->where('settlement_run_id', $run->id())->count())->toBe(1);
});

it('refuses to compute while the compute flag is off', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000002', 500_000, 'flagoff2@example.com');
    config(['flags.overrides.settlement.compute' => 'false']);
    [$from, $to] = window();

    expect(fn () => runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to))
        ->toThrow(PaymentsInvalidState::class, 'switched off');
});

it('refuses a second live run for the same window', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000003', 600_000, 'dupwindow@example.com');
    [$from, $to] = window();

    runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);

    expect(fn () => runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to))
        ->toThrow(PaymentsInvalidState::class, 'already exists');
});

it('refuses to let the approver also execute', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000004', 700_000, 'foureyes@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());

    expect(fn () => runs()->execute(APPROVER, $run->id()))
        ->toThrow(PaymentsNotAuthorized::class, 'cannot also execute');
});

it('settles to the merchant wallet and moves the ledger', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000005', 1_000_000, 'wallet@example.com');
    [$from, $to] = window();

    $ledger = app(LedgerService::class);
    $payableBefore = $ledger->balanceOf(LedgerAccount::MerchantPayable);
    $payoutsBefore = $ledger->balanceOf(LedgerAccount::Payouts);

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    $settled = runs()->execute(EXECUTOR, $run->id());

    expect($settled->state())->toBe(SettlementRunState::Succeeded)
        ->and($ledger->balanceOf(LedgerAccount::MerchantPayable))->toBe($payableBefore - $net)
        ->and($ledger->balanceOf(LedgerAccount::Payouts))->toBe($payoutsBefore + $net)
        ->and(app(LedgerRepository::class)->netMinor())->toBe(0);

    // And the payable is now zero: the accrual is settled.
    expect(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe(0);
});

it('settles to a bank account through the provider', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000006', 900_000, 'bank@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    $settled = runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    expect($settled->state())->toBe(SettlementRunState::Succeeded);

    $attempts = app(PayoutAttemptRepository::class)->forRun($run->id());
    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]->state())->toBe(PayoutAttemptState::Confirmed)
        ->and($attempts[0]->amount()->minorUnits)->toBe($net)
        ->and($attempts[0]->providerReference())->not->toBeNull();
});

it('leaves an unknown transfer unknown, and refuses to retry it', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000007', 800_000, 'unknown@example.com');
    [$from, $to] = window();

    // The provider times out. This is the case that used to read as a decline.
    MockGateway::nextTransfer(GatewayOutcome::Unknown);

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    $settled = runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    expect($settled->state())->toBe(SettlementRunState::Unknown)
        ->and($settled->state()->allowsNewAttempt())->toBeFalse()
        ->and($settled->state()->requiresReconciliation())->toBeTrue();

    // The invariant: no path from Unknown back to a money-moving state.
    expect(fn () => runs()->retry(APPROVER, $run->id()))
        ->toThrow(PaymentsInvalidState::class, 'must be reconciled');

    // The accruals stay reserved — the money may already be gone.
    expect(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe(0);
});

it('never allows a transition from unknown into pending or processing', function (): void {
    // Asserted on the state machine directly, so it holds for every caller and
    // not merely the one the service happens to expose.
    $reachable = SettlementRunState::Unknown->allowedNext();

    expect($reachable)->toBe([SettlementRunState::Reconciling])
        ->and(SettlementRunState::Unknown->canTransitionTo(SettlementRunState::Pending))->toBeFalse()
        ->and(SettlementRunState::Unknown->canTransitionTo(SettlementRunState::Processing))->toBeFalse()
        ->and(SettlementRunState::Unknown->canTransitionTo(SettlementRunState::Succeeded))->toBeFalse();
});

it('confirms an unknown transfer when the provider says the money went', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000008', 750_000, 'reconok@example.com');
    [$from, $to] = window();

    MockGateway::nextTransfer(GatewayOutcome::Unknown);
    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    $ledger = app(LedgerService::class);
    $payoutsBefore = $ledger->balanceOf(LedgerAccount::Payouts);

    // The provider, asked later, confirms it.
    $summary = app(SettlementReconciliationService::class)->reconcilePayouts();

    $after = app(SettlementRunRepository::class)->findById($run->id());
    expect($summary['confirmed'])->toBe(1)
        ->and($after->state())->toBe(SettlementRunState::Succeeded)
        // The ledger entries the crashed attempt never posted are posted now.
        ->and($ledger->balanceOf(LedgerAccount::Payouts))->toBe($payoutsBefore + $net)
        ->and(app(LedgerRepository::class)->netMinor())->toBe(0);
});

it('releases the accruals when the provider says the transfer never happened', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000009', 650_000, 'reconfail@example.com');
    [$from, $to] = window();

    MockGateway::nextTransfer(GatewayOutcome::Unknown);
    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    $executed = runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    // Now the provider answers definitively: it did not happen.
    MockGateway::answerStatus(
        app(PayoutAttemptRepository::class)->forRun($run->id())[0]->providerReference(),
        GatewayOutcome::Failed,
    );

    $summary = app(SettlementReconciliationService::class)->reconcilePayouts();

    $after = app(SettlementRunRepository::class)->findById($run->id());
    expect($summary['failed'])->toBe(1)
        ->and($after->state())->toBe(SettlementRunState::Failed)
        // Released, so the merchant is owed the money again and a later run
        // can pay it. Safe only because the provider *said so*.
        ->and(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe($net)
        ->and(SettlementLineModel::query()->where('settlement_run_id', $run->id())->count())->toBe(0);
});

it('escalates to a human when the provider cannot say either way', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000010', 550_000, 'escalate@example.com');
    [$from, $to] = window();

    MockGateway::nextTransfer(GatewayOutcome::Unknown);
    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    MockGateway::answerStatus(
        app(PayoutAttemptRepository::class)->forRun($run->id())[0]->providerReference(),
        GatewayOutcome::Unknown,
    );

    $summary = app(SettlementReconciliationService::class)->reconcilePayouts();

    $after = app(SettlementRunRepository::class)->findById($run->id());
    expect($summary['cases_opened'])->toBe(1)
        ->and($after->state())->toBe(SettlementRunState::ReconciliationRequired)
        // Terminal. Nothing automatic moves it on from here.
        ->and($after->state()->isTerminal())->toBeTrue()
        // And the accruals are still held: the money may be gone.
        ->and(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe(0);
});

it('retries a genuinely failed run as a new attempt, never a reset', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000011', 450_000, 'retry@example.com');
    [$from, $to] = window();

    MockGateway::nextTransfer(GatewayOutcome::Failed);
    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    $failed = runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    expect($failed->state())->toBe(SettlementRunState::Failed);

    // A different person re-approves; the executor slot was cleared, so the
    // four-eyes rule applies to the retry too.
    runs()->retry(EXECUTOR, $run->id(), 'provider outage resolved');
    $settled = runs()->execute(APPROVER, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    $attempts = app(PayoutAttemptRepository::class)->forRun($run->id());
    expect($settled->state())->toBe(SettlementRunState::Succeeded)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->state())->toBe(PayoutAttemptState::Rejected)
        ->and($attempts[1]->state())->toBe(PayoutAttemptState::Confirmed)
        // A new attempt carries a *different* idempotency key: the provider
        // must treat it as a new instruction, not a replay of the failed one.
        ->and($attempts[0]->idempotencyKey())->not->toBe($attempts[1]->idempotencyKey());
});

it('cancels a draft and gives the accruals back', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000012', 350_000, 'cancel@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    expect(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe(0);

    runs()->cancel(APPROVER, $run->id(), 'wrong window');

    expect(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe($net)
        ->and(SettlementLineModel::query()->where('settlement_run_id', $run->id())->count())->toBe(0);
});

it('reverses a completed settlement with compensating entries and no edits', function (): void {
    $net = accrue($this, '77777777-7777-4777-8777-000000000013', 250_000, 'reverse@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    runs()->execute(EXECUTOR, $run->id());

    $ledger = app(LedgerService::class);
    $entriesBefore = count(app(LedgerRepository::class)->forCorrelation($run->id()));

    $reversed = runs()->reverse(APPROVER, $run->id(), 'merchant returned the funds');

    expect($reversed->state())->toBe(SettlementRunState::Reversed)
        // The original posting is untouched; the reversal is a second one under
        // its own correlation.
        ->and(count(app(LedgerRepository::class)->forCorrelation($run->id())))->toBe($entriesBefore)
        // The reversal is a separate posting under its own correlation. It is
        // found by the settlement reference the two share, not by a derived id
        // — `correlation_id` is a uuid column, so a derived string cannot be one.
        ->and(\EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel::query()
            ->where('reference', $run->settlementReference())->count())->toBe(4)
        ->and(app(LedgerRepository::class)->netMinor())->toBe(0)
        ->and(runs()->payableFor('vendor', VENDOR)->amount()->minorUnits)->toBe($net);
});

it('refuses a settlement line for an accrual another run already holds', function (): void {
    // The last-line guarantee, exercised at the database rather than the
    // service: two runs, one accrual, one of them must lose.
    $orderId = '77777777-7777-4777-8777-000000000014';
    accrue($this, $orderId, 150_000, 'doubleline@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    $accrualId = app(PayableAccrualRepository::class)->findEarningForOrder($orderId)->id();

    expect(fn () => SettlementLineModel::query()->insert([
        'id' => (string) Illuminate\Support\Str::orderedUuid(),
        'settlement_run_id' => $run->id(),
        'accrual_id' => $accrualId,
        'currency' => 'NGN',
        'net_minor' => 150_000,
        'created_at' => new DateTimeImmutable(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('reports a payable drift between the ledger and the accruals', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000015', 200_000, 'drift@example.com');

    // Healthy to begin with.
    expect(app(SettlementReconciliationService::class)->reconcileLedgerAgainstPayable())->toBeNull();

    // Post a MerchantPayable movement with no accrual behind it — the shape of
    // a half-applied write.
    $ledger = app(LedgerService::class);
    $ledger->commit(
        $ledger->newPosting((string) Illuminate\Support\Str::orderedUuid(), \EruoFood\Payments\Domain\Enum\TransactionType::Settlement, null)
            ->debit(LedgerAccount::Escrow, new \EruoFood\Shared\Domain\ValueObject\Money(5_000))
            ->credit(LedgerAccount::MerchantPayable, new \EruoFood\Shared\Domain\ValueObject\Money(5_000)),
    );

    $case = app(SettlementReconciliationService::class)->reconcileLedgerAgainstPayable();

    expect($case)->not->toBeNull()
        ->and($case->kind())->toBe(\EruoFood\Payments\Domain\Enum\DiscrepancyKind::PayableDrift)
        ->and($case->differenceMinor())->toBe(-5_000);
});

it('opens exactly one case for a discrepancy that persists across sweeps', function (): void {
    $ledger = app(LedgerService::class);
    $ledger->commit(
        $ledger->newPosting((string) Illuminate\Support\Str::orderedUuid(), \EruoFood\Payments\Domain\Enum\TransactionType::Settlement, null)
            ->debit(LedgerAccount::Escrow, new \EruoFood\Shared\Domain\ValueObject\Money(3_000))
            ->credit(LedgerAccount::MerchantPayable, new \EruoFood\Shared\Domain\ValueObject\Money(3_000)),
    );

    $service = app(SettlementReconciliationService::class);
    $first = $service->reconcileLedgerAgainstPayable();
    $second = $service->reconcileLedgerAgainstPayable();
    $third = $service->reconcileLedgerAgainstPayable();

    expect($first->id())->toBe($second->id())->and($second->id())->toBe($third->id())
        ->and(app(\EruoFood\Payments\Domain\Settlement\ReconciliationCaseRepository::class)->unresolvedCount())->toBe(1);
});

it('does nothing at all while the reconcile flag is off', function (): void {
    config(['flags.overrides.settlement.reconcile' => 'false']);

    $ledger = app(LedgerService::class);
    $ledger->commit(
        $ledger->newPosting((string) Illuminate\Support\Str::orderedUuid(), \EruoFood\Payments\Domain\Enum\TransactionType::Settlement, null)
            ->debit(LedgerAccount::Escrow, new \EruoFood\Shared\Domain\ValueObject\Money(1_000))
            ->credit(LedgerAccount::MerchantPayable, new \EruoFood\Shared\Domain\ValueObject\Money(1_000)),
    );

    $service = app(SettlementReconciliationService::class);

    expect($service->reconcileLedgerAgainstPayable())->toBeNull()
        ->and($service->reconcilePayouts()['examined'])->toBe(0);
});

it('records a full audit trail for every privileged financial action', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000016', 500_000, 'audit@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id(), 'figures check out');
    runs()->execute(EXECUTOR, $run->id());

    $entries = \EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AuditLogModel::query()
        ->where('subject_id', $run->id())
        ->orderBy('created_at')
        ->get();

    $actions = $entries->pluck('action')->all();

    expect($actions)->toContain('finance.settlement_computed')
        ->and($actions)->toContain('finance.settlement_approved')
        ->and($actions)->toContain('finance.settlement_executed');

    $approval = $entries->firstWhere('action', 'finance.settlement_approved');
    $context = is_array($approval->context) ? $approval->context : json_decode((string) $approval->context, true);

    // Requirement: actor, action, target, amount, currency, reason,
    // correlation id, idempotency key, before/after state and result.
    expect($approval->actor_id)->toBe(APPROVER)
        ->and($approval->subject_type)->toBe('settlement_run')
        ->and($approval->category)->toBe('finance')
        ->and($context['amountMinor'])->toBe($run->net()->minorUnits)
        ->and($context['currency'])->toBe('NGN')
        ->and($context['reason'])->toBe('figures check out')
        ->and($context['correlationId'])->not->toBeEmpty()
        ->and($context['idempotencyKey'])->not->toBeEmpty()
        ->and($context['beforeState'])->toBe('draft')
        ->and($context['afterState'])->toBe('pending')
        ->and($context['result'])->toBe('succeeded');
});

it('keeps no bank details or provider payload in the audit trail', function (): void {
    accrue($this, '77777777-7777-4777-8777-000000000017', 300_000, 'noleak@example.com');
    [$from, $to] = window();

    $run = runs()->computeDraft(APPROVER, 'vendor', VENDOR, $from, $to);
    runs()->approve(APPROVER, $run->id());
    runs()->execute(EXECUTOR, $run->id(), new BankAccount('Vendor Ltd', '0123456789', '058'));

    $dump = \EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AuditLogModel::query()
        ->where('subject_id', $run->id())->get()->toJson();

    expect($dump)->not->toContain('0123456789')
        ->and($dump)->not->toContain('account_number');

    // Nor does the attempt row keep the raw provider response — only a digest,
    // which by construction cannot contain an account number.
    foreach (app(PayoutAttemptRepository::class)->forRun($run->id()) as $attempt) {
        $digest = $attempt->rawResponseDigest();
        expect($digest === null || preg_match('/^[a-f0-9]{64}$/', $digest) === 1)->toBeTrue();
    }
});
