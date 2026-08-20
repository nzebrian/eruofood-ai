<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Event\FinancialActionAudited;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Settlement\MerchantPayable;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\PayoutAttempt;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Domain\Settlement\SettlementLine;
use EruoFood\Payments\Domain\Settlement\SettlementReference;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagTarget;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Computing, approving and executing settlement runs.
 *
 * ## The three acts, and why they are three
 *
 * {@see computeDraft()} derives what a merchant is owed and writes it down.
 * {@see approve()} is a person saying the figure is right. {@see execute()} is
 * a *different* person moving the money. None of them accepts an amount.
 *
 * Splitting them is what makes the figure reviewable. A single "settle this
 * merchant" call with a number in it — which is what M27 replaces — has no
 * point at which anybody could have disagreed.
 *
 * ## The execution sequence, and the crash window it closes
 *
 * ```
 *   ┌─ transaction ─────────────────────────────────────┐
 *   │ lock merchant → re-read run under lock → check    │
 *   │ state → mark processing → write payout attempt    │
 *   └───────────────────────────────────────────────────┘
 *                    ↓ commit
 *              provider transfer          ← no transaction, no locks held
 *                    ↓
 *   ┌─ transaction ─────────────────────────────────────┐
 *   │ re-read under lock → record outcome → ledger      │
 *   └───────────────────────────────────────────────────┘
 * ```
 *
 * The attempt row is committed *before* the provider is called. A process that
 * dies mid-transfer therefore leaves a `created` attempt, which the reconciler
 * looks for. The old implementation wrote nothing until after the transfer
 * returned, so a crash there lost the money silently.
 *
 * The network call is outside any transaction on purpose — holding a row lock
 * across a call that can take twenty seconds is how a settlement run blocks
 * every other write on the merchant. That choice is exactly why the third phase
 * has to handle {@see GatewayOutcome::Unknown} rather than assume.
 *
 * ## Concurrency
 *
 * Four layers, in this order: the merchant advisory lock, the run's row lock
 * with an in-lock state re-read, the optimistic `version` on write, and the
 * partial unique indexes underneath all of it. The first three can be
 * refactored away by accident. The fourth cannot.
 */
final readonly class SettlementRunService
{
    public const FLAG_COMPUTE = 'settlement.compute';

    public const FLAG_EXECUTE = 'settlement.execute';

    public function __construct(
        private SettlementRunRepository $runs,
        private PayableAccrualRepository $accruals,
        private PayoutAttemptRepository $attempts,
        private PaymentGatewayFactory $gateways,
        private WalletService $wallets,
        private LedgerService $ledger,
        private SettlementNotifier $notifier,
        private TransactionManager $transactions,
        private EventBus $events,
        private FlagEvaluator $flags,
        private Clock $clock,
        private string $currency,
    ) {
    }

    public function runOrFail(string $runId): SettlementRun
    {
        return $this->runs->findById($runId) ?? throw PaymentsNotFound::of('settlement run', $runId);
    }

    /** What a merchant is owed right now, derived from accruals and lines. */
    public function payableFor(string $merchantType, string $merchantId, ?string $currency = null): MerchantPayable
    {
        $ccy = $currency ?? $this->currency;

        return MerchantPayable::of(
            $merchantType,
            $merchantId,
            $this->accruals->derivedPayableMinor($merchantType, $merchantId, $ccy),
            $ccy,
        );
    }

    /**
     * Build a draft run over every unsettled accrual in the window.
     *
     * Reserves the accruals as it goes: the lines are written with the draft,
     * so two concurrent computations cannot both claim the same orders. The
     * draft moves no money, so reserving early costs nothing and cancelling
     * releases them again.
     */
    public function computeDraft(
        ?string $actorId,
        string $merchantType,
        string $merchantId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?string $idempotencyKey = null,
        ?string $currency = null,
    ): SettlementRun {
        $ccy = $currency ?? $this->currency;
        $this->assertEnabled(self::FLAG_COMPUTE, $merchantId, 'Settlement computation is switched off.');

        return $this->transactions->atomic(function () use ($actorId, $merchantType, $merchantId, $ccy, $windowStart, $windowEnd, $idempotencyKey): SettlementRun {
            // Merchant-first, always. Two runs for different merchants never
            // contend; two for the same merchant serialise here rather than
            // discovering each other at the unique index.
            $this->runs->lockMerchant($merchantType, $merchantId);

            $existing = $this->runs->liveRunForWindow($merchantType, $merchantId, $ccy, $windowStart, $windowEnd);
            if ($existing !== null) {
                throw new PaymentsInvalidState(sprintf(
                    'A settlement run for this window already exists and is "%s".',
                    $existing->state()->value,
                ));
            }

            $accruals = $this->accruals->unsettledEarnings($merchantType, $merchantId, $ccy, $windowStart, $windowEnd);
            if ($accruals === []) {
                throw new PaymentsInvalidState('There is nothing to settle for this merchant and window.');
            }

            $gross = new Money(0, $ccy);
            $commission = new Money(0, $ccy);
            $fee = new Money(0, $ccy);
            foreach ($accruals as $accrual) {
                $gross = $gross->add($accrual->gross());
                $commission = $commission->add($accrual->commission());
                $fee = $fee->add($accrual->fee());
            }

            // The payable can be lower than the sum of these accruals, because
            // refund adjustments are not themselves settleable lines. Settling
            // more than the merchant is owed would overdraw them, so the run is
            // capped by the derived payable rather than by its own arithmetic.
            $payable = $this->accruals->derivedPayableMinor($merchantType, $merchantId, $ccy);
            $net = $gross->subtract($commission)->subtract($fee);
            if ($payable < $net->minorUnits) {
                throw new PaymentsInvalidState(sprintf(
                    'Refunds have reduced this merchant\'s payable to %d, below the %d these accruals total. '
                    .'Settle a narrower window, or resolve the difference first.',
                    $payable,
                    $net->minorUnits,
                ));
            }

            $reference = SettlementReference::for($merchantType, $merchantId, $ccy, $windowStart, $windowEnd);
            $runId = $this->runs->nextIdentity();

            $run = SettlementRun::draft(
                id: $runId,
                merchantType: $merchantType,
                merchantId: $merchantId,
                currency: $ccy,
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                gross: $gross,
                commission: $commission,
                fee: $fee,
                idempotencyKey: $idempotencyKey,
                settlementReference: $reference->value,
                correlationId: CorrelationContext::forAudit(),
                computedBy: $actorId,
                now: $this->clock->now(),
            );

            $lines = array_map(
                fn (PayableAccrual $accrual): SettlementLine => SettlementLine::forAccrual(
                    $this->runs->nextIdentity(),
                    $runId,
                    $accrual,
                    $this->clock->now(),
                ),
                $accruals,
            );

            $this->runs->insert($run, $lines);
            $this->audit($actorId, 'finance.settlement_computed', $run, null, 'succeeded', sprintf('%d accruals', count($lines)));

            return $run;
        });
    }

    /** A named person agrees the draft is right. */
    public function approve(string $actorId, string $runId, ?string $reason = null): SettlementRun
    {
        return $this->transactions->atomic(function () use ($actorId, $runId, $reason): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();
            $expected = $run->version();

            $run->approve($actorId, $this->clock->now());
            $this->runs->update($run, $expected);

            $this->audit($actorId, 'finance.settlement_approved', $run, $before, 'succeeded', $reason);

            return $run;
        });
    }

    public function cancel(string $actorId, string $runId, ?string $reason = null): SettlementRun
    {
        return $this->transactions->atomic(function () use ($actorId, $runId, $reason): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();

            $expected = $run->version();
            $run->cancel($this->clock->now());
            $this->runs->update($run, $expected);
            // Cancelling frees the accruals for a later run. Doing this inside
            // the same transaction matters: a cancelled run whose lines survived
            // would hold those orders hostage for ever.
            $this->runs->releaseLines($run);

            $this->audit($actorId, 'finance.settlement_cancelled', $run, $before, 'succeeded', $reason);

            return $run;
        });
    }

    /**
     * Move the money.
     *
     * When $destination is null the net is credited to the merchant's wallet —
     * an internal movement with no provider, so it commits atomically and
     * cannot end in `Unknown`. When a bank account is given the transfer goes
     * through the provider, and every outcome including silence is handled.
     */
    public function execute(string $actorId, string $runId, ?BankAccount $destination = null): SettlementRun
    {
        $run = $this->runs->findById($runId) ?? throw PaymentsNotFound::of('settlement run', $runId);
        $this->assertEnabled(self::FLAG_EXECUTE, $run->merchantId(), 'Settlement execution is switched off.');

        return $destination === null
            ? $this->executeToWallet($actorId, $runId)
            : $this->executeToBank($actorId, $runId, $destination);
    }

    /** A failed run may be attempted again. An unknown one may not. */
    public function retry(string $actorId, string $runId, ?string $reason = null): SettlementRun
    {
        return $this->transactions->atomic(function () use ($actorId, $runId, $reason): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();

            // The aggregate refuses anything but Failed. Stated here as well
            // because the message a caller sees should name the actual problem:
            // an unknown run needs reconciling, not retrying.
            if ($before === SettlementRunState::Unknown || $before === SettlementRunState::Reconciling) {
                throw new PaymentsInvalidState(
                    'This settlement\'s outcome is unknown. It must be reconciled before another attempt — '
                    .'retrying could pay the merchant twice.',
                );
            }

            $expected = $run->version();
            $run->reopenForRetry($actorId, $this->clock->now());
            $this->runs->update($run, $expected);

            $this->audit($actorId, 'finance.settlement_retried', $run, $before, 'succeeded', $reason);

            return $run;
        });
    }

    /** Reverse a completed settlement by compensating entries. Never by editing. */
    public function reverse(string $actorId, string $runId, string $reason): SettlementRun
    {
        if (trim($reason) === '') {
            throw new PaymentsInvalidState('A reversal needs a reason.');
        }

        return $this->transactions->atomic(function () use ($actorId, $runId, $reason): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();

            $expected = $run->version();
            $run->reverse($this->clock->now());
            $this->runs->update($run, $expected);
            $this->runs->releaseLines($run);

            // Compensating, not corrective: the original posting stays exactly
            // where it is and a second one moves the money back.
            //
            // Its own correlation id, generated rather than derived. The
            // obvious `$run->id().':reversal'` reads well and is not a uuid —
            // `payments_ledger_entries.correlation_id` is a `uuid` column, so
            // PostgreSQL rejects it outright while SQLite accepts anything.
            // That would have made every reversal fail on the production engine
            // and pass in the fast test suite.
            //
            // The link back to the run survives through `reference`, which
            // carries the settlement reference on both postings.
            $reversalCorrelation = $this->runs->nextIdentity();
            $this->ledger->commit(
                $this->ledger->newPosting($reversalCorrelation, TransactionType::Settlement, $run->settlementReference())
                    ->debit(LedgerAccount::Payouts, $run->net())
                    ->credit(LedgerAccount::MerchantPayable, $run->net()),
            );

            // The reversal's correlation goes into the audit reason so the
            // compensating posting is findable from the trail, which is what a
            // reconciliation case's `compensating_posting_id` needs.
            $this->audit(
                $actorId,
                'finance.settlement_reversed',
                $run,
                $before,
                'succeeded',
                $reason.' [compensating posting '.$reversalCorrelation.']',
            );

            return $run;
        });
    }

    private function executeToWallet(string $actorId, string $runId): SettlementRun
    {
        $run = $this->transactions->atomic(function () use ($actorId, $runId): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();

            $expected = $run->version();
            $run->beginExecution($actorId, $this->clock->now());
            $this->runs->update($run, $expected);

            $wallet = $this->wallets->getOrOpen($this->walletOwnerType($run->merchantType()), $run->merchantId());
            $this->wallets->credit(
                $wallet,
                $run->net()->minorUnits,
                TransactionType::Settlement,
                $run->id(),
                'Settlement '.$run->settlementReference(),
            );

            $this->ledger->commit(
                $this->ledger->newPosting($run->id(), TransactionType::Settlement, $run->settlementReference())
                    ->debit(LedgerAccount::MerchantPayable, $run->net())
                    ->credit(LedgerAccount::Payouts, $run->net()),
            );

            $expected = $run->version();
            $run->markSucceeded($this->clock->now());
            $this->runs->update($run, $expected);

            $this->audit($actorId, 'finance.settlement_executed', $run, $before, 'succeeded', 'wallet credit');

            return $run;
        });

        // Outside the transaction: a notification that escapes cannot be rolled
        // back, and telling a merchant they have been paid before the commit is
        // the one ordering mistake here that reaches a person.
        $this->notifier->settlementSucceeded($run);

        return $run;
    }

    private function executeToBank(string $actorId, string $runId, BankAccount $destination): SettlementRun
    {
        // ---- Phase 1: reserve, and write the attempt down before calling out.
        [$run, $attempt] = $this->transactions->atomic(function () use ($actorId, $runId): array {
            $run = $this->lockedRun($runId);
            $expected = $run->version();
            $run->beginExecution($actorId, $this->clock->now());
            $this->runs->update($run, $expected);

            $gateway = $this->gateways->default();
            $attemptNo = $this->attempts->lastAttemptNo($run->id()) + 1;

            $attempt = PayoutAttempt::create(
                id: $this->attempts->nextIdentity(),
                settlementRunId: $run->id(),
                attemptNo: $attemptNo,
                provider: $gateway->provider(),
                amount: $run->net(),
                // Derived from the run and the attempt number, so a retried
                // transfer carries a *different* key — the provider must treat
                // it as a new instruction — while a repeated call for the same
                // attempt carries the same one.
                idempotencyKey: sprintf('%s:%d', $run->settlementReference(), $attemptNo),
                correlationId: CorrelationContext::forAudit(),
                now: $this->clock->now(),
            );

            $this->attempts->insert($attempt);
            $this->audit($actorId, 'finance.payout_submitted', $run, SettlementRunState::Pending, 'pending', 'attempt '.$attemptNo);

            return [$run, $attempt];
        });

        // ---- Phase 2: the provider. No transaction, no locks held.
        $result = $this->gateways->default()->transfer($destination, $run->net(), $attempt->idempotencyKey());
        $outcome = $result->outcome();

        // ---- Phase 3: record what happened, whatever it was.
        $settled = $this->transactions->atomic(function () use ($actorId, $runId, $attempt, $result, $outcome): SettlementRun {
            $run = $this->lockedRun($runId);
            $before = $run->state();
            $expected = $run->version();

            $attempt->applyOutcome(
                $outcome,
                $result->providerReference,
                $result->message,
                // A digest, never the payload: provider responses echo account
                // numbers, and sometimes the request that produced them.
                $result->raw === [] ? null : hash('sha256', (string) json_encode($result->raw)),
                $this->clock->now(),
            );
            $this->attempts->update($attempt);

            match ($outcome) {
                GatewayOutcome::Succeeded => $this->completeBankPayout($run),
                GatewayOutcome::Failed => $this->failRun($run, $result->message ?? 'The provider declined the transfer.'),
                // Processing and Unknown both leave the run un-finished, and
                // neither releases its lines. The accruals stay reserved
                // because the money may already have gone.
                GatewayOutcome::Processing, GatewayOutcome::Unknown => $run->markUnknown(
                    $result->message ?? 'The provider did not confirm the transfer.',
                    $this->clock->now(),
                ),
            };

            $this->runs->update($run, $expected);
            $this->audit($actorId, 'finance.payout_recorded', $run, $before, $outcome->value, $result->message);

            return $run;
        });

        $this->notify($settled);

        return $settled;
    }

    private function completeBankPayout(SettlementRun $run): void
    {
        $this->ledger->commit(
            $this->ledger->newPosting($run->id(), TransactionType::Settlement, $run->settlementReference())
                ->debit(LedgerAccount::MerchantPayable, $run->net())
                ->credit(LedgerAccount::Payouts, $run->net()),
        );

        $run->markSucceeded($this->clock->now());
    }

    private function failRun(SettlementRun $run, string $reason): void
    {
        $run->markFailed($reason, $this->clock->now());
        // Safe precisely because the provider *said* it declined. Nothing
        // moved, so the accruals go back to the payable and can be settled by
        // a later run.
        $this->runs->releaseLines($run);
    }

    private function notify(SettlementRun $run): void
    {
        match ($run->state()) {
            SettlementRunState::Succeeded => $this->notifier->settlementSucceeded($run),
            SettlementRunState::Failed => $this->notifier->settlementFailed($run),
            SettlementRunState::Unknown => $this->notifier->reconciliationRequired($run),
            default => null,
        };
    }

    /** Read the run under an exclusive lock, so the state check that follows is honest. */
    private function lockedRun(string $runId): SettlementRun
    {
        return $this->runs->findByIdForUpdate($runId)
            ?? throw PaymentsNotFound::of('settlement run', $runId);
    }

    private function assertEnabled(string $flag, string $merchantId, string $message): void
    {
        if (! $this->flags->isEnabled($flag, FlagTarget::of(merchantId: $merchantId))) {
            throw new PaymentsInvalidState($message);
        }
    }

    private function walletOwnerType(string $merchantType): WalletOwnerType
    {
        return WalletOwnerType::tryFrom($merchantType) ?? WalletOwnerType::Vendor;
    }

    private function audit(
        ?string $actorId,
        string $action,
        SettlementRun $run,
        ?SettlementRunState $before,
        string $result,
        ?string $reason,
    ): void {
        $this->events->publish(new FinancialActionAudited(
            actorId: $actorId,
            auditAction: $action,
            subjectType: 'settlement_run',
            subjectId: $run->id(),
            amountMinor: $run->net()->minorUnits,
            currency: $run->currency(),
            reason: $reason,
            correlationId: CorrelationContext::forAudit(),
            idempotencyKey: $run->idempotencyKey() ?? $run->settlementReference(),
            beforeState: $before?->value,
            afterState: $run->state()->value,
            result: $result,
        ));
    }
}
