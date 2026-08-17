<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Port\PayoutGateway;
use EruoFood\Payments\Domain\Enum\DiscrepancyKind;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\PayoutAttemptState;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Event\FinancialActionAudited;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\PayoutAttempt;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\ReconciliationCaseRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Finding out what actually happened, and never pretending to know.
 *
 * ## Four reconcilers, one rule
 *
 * | Reconciler | Compares | Opens |
 * |---|---|---|
 * | {@see reconcilePayouts()} | provider transfer status vs our attempt | `PayoutStateMismatch` |
 * | {@see reconcileLedgerAgainstPayable()} | MerchantPayable balance vs derived payable | `PayableDrift` |
 * | {@see reconcileLedgerAgainstWallets()} | ledger wallet accounts vs wallet balances | `LedgerWalletDrift` |
 * | {@see reconcilePaymentsAgainstAccruals()} | captured payments vs accruals | `MissingAccrual` |
 *
 * The rule they share: **a reconciler may close a case only when the two sides
 * agree.** It has no code path that edits a ledger entry, an accrual, a run or
 * an attempt to make a difference go away. When the sides still disagree it
 * opens a case and stops, and a person decides.
 *
 * That is why {@see DiscrepancyKind::isAutoResolvable()} is true for exactly one
 * kind. A provider mismatch can resolve itself — the provider was down, we
 * asked again, it now agrees. A drift between two numbers the platform itself
 * wrote cannot: asking twice gives the same answer, and a system that "resolved"
 * it would be hiding its own contradiction.
 *
 * ## What happens to an unknown payout
 *
 * `Unknown` → ask the provider →
 * - it says **succeeded** → the money did go. Post the ledger entries that the
 *   crashed attempt never posted, and confirm the run.
 * - it says **failed** → nothing moved. Release the accruals; a new attempt is
 *   safe.
 * - it says **processing** → still in flight. Leave it; ask again next sweep.
 * - it says **unknown**, or the provider cannot be asked at all → open a case
 *   and move the run to `ReconciliationRequired`. A human now owns it.
 *
 * The third and fourth are different on purpose. "Still working on it" is an
 * answer worth waiting on; "I cannot tell you" is not.
 */
final readonly class SettlementReconciliationService
{
    public const FLAG_RECONCILE = 'settlement.reconcile';

    public function __construct(
        private SettlementRunRepository $runs,
        private PayoutAttemptRepository $attempts,
        private PayableAccrualRepository $accruals,
        private ReconciliationCaseRepository $cases,
        private LedgerRepository $ledgerRepository,
        private LedgerService $ledger,
        private PaymentGatewayFactory $gateways,
        private SettlementNotifier $notifier,
        private TransactionManager $transactions,
        private EventBus $events,
        private FlagEvaluator $flags,
        private Clock $clock,
        private string $currency,
    ) {
    }

    /**
     * Ask the provider about every attempt whose outcome nobody established.
     *
     * @return array{examined: int, confirmed: int, failed: int, still_unknown: int, cases_opened: int}
     */
    public function reconcilePayouts(int $limit = 50): array
    {
        $summary = ['examined' => 0, 'confirmed' => 0, 'failed' => 0, 'still_unknown' => 0, 'cases_opened' => 0];

        if (! $this->flags->isEnabled(self::FLAG_RECONCILE)) {
            return $summary;
        }

        foreach ($this->attempts->needingReconciliation($limit) as $attempt) {
            $summary['examined']++;
            $outcome = $this->askProvider($attempt);

            match ($outcome) {
                GatewayOutcome::Succeeded => $summary['confirmed'] += $this->confirmFromProvider($attempt) ? 1 : 0,
                GatewayOutcome::Failed => $summary['failed'] += $this->failFromProvider($attempt) ? 1 : 0,
                // Still working on it. Nothing to record, nothing to escalate;
                // the next sweep asks again.
                GatewayOutcome::Processing => null,
                GatewayOutcome::Unknown => [
                    $summary['still_unknown']++,
                    $summary['cases_opened'] += $this->escalate($attempt) ? 1 : 0,
                ],
            };
        }

        return $summary;
    }

    /**
     * The MerchantPayable ledger balance against the sum of what we owe.
     *
     * These are two independent derivations of the same number: one from
     * double-entry postings, one from accrual rows minus settlement lines. They
     * must be equal. When they are not, one of the two writes went missing, and
     * neither can be trusted until somebody says which.
     */
    public function reconcileLedgerAgainstPayable(): ?ReconciliationCase
    {
        if (! $this->flags->isEnabled(self::FLAG_RECONCILE)) {
            return null;
        }

        $ledgerBalance = $this->ledger->balanceOf(LedgerAccount::MerchantPayable);
        // Both sides derived independently: the ledger from postings, this from
        // rows. `paidOutNetMinor()` counts only runs that actually posted —
        // reserved-but-unpaid lines must not appear here, or every in-flight
        // settlement would look like a drift.
        $derived = $this->accruals->postedNetMinor() - $this->runs->paidOutNetMinor();

        if ($ledgerBalance === $derived) {
            return null;
        }

        return $this->open(
            DiscrepancyKind::PayableDrift,
            'platform',
            // A stable literal, not a null: this is what lets the partial
            // unique index recognise the same drift on the next sweep.
            'merchant_payable',
            new Money($derived, $this->currency),
            new Money($ledgerBalance, $this->currency),
            sprintf('Derived payable %d, MerchantPayable ledger balance %d.', $derived, $ledgerBalance),
        );
    }

    /**
     * The ledger's whole-book invariant, restated where settlement can break it.
     *
     * Every posting balances, so the signed sum of the book must be zero. If a
     * settlement path ever committed half a posting, this is what notices.
     */
    public function reconcileLedgerAgainstWallets(): ?ReconciliationCase
    {
        if (! $this->flags->isEnabled(self::FLAG_RECONCILE)) {
            return null;
        }

        $net = $this->ledgerRepository->netMinor();
        $unbalanced = $this->ledgerRepository->unbalancedCorrelations();

        if ($net === 0 && $unbalanced === []) {
            return null;
        }

        return $this->open(
            DiscrepancyKind::LedgerWalletDrift,
            'ledger',
            'whole_book',
            new Money(0, $this->currency),
            new Money($net, $this->currency),
            sprintf('Ledger net %d with %d unbalanced posting(s).', $net, count($unbalanced)),
        );
    }

    /**
     * Settleable accruals that no confirmed payment stands behind.
     *
     * The opposite direction from the others: rather than comparing two totals,
     * it looks for a row that should not exist. An accrual whose payment is
     * gone would pay a merchant for an order nobody paid for.
     *
     * @return list<ReconciliationCase>
     */
    public function reconcilePaymentsAgainstAccruals(int $limit = 100): array
    {
        if (! $this->flags->isEnabled(self::FLAG_RECONCILE)) {
            return [];
        }

        $opened = [];

        foreach ($this->accruals->orphanEarnings($limit) as $orphan) {
            $opened[] = $this->open(
                DiscrepancyKind::OrphanAccrual,
                'payable_accrual',
                $orphan['accrual_id'],
                new Money(0, $this->currency),
                new Money($orphan['net_minor'], $this->currency),
                sprintf('Accrual references payment %s, which is not captured.', $orphan['payment_id']),
            );
        }

        return $opened;
    }

    /**
     * Reconcile one run on demand, for an operator who will not wait for a sweep.
     */
    public function reconcileRun(string $runId): SettlementRun
    {
        $run = $this->runs->findById($runId) ?? throw PaymentsNotFound::of('settlement run', $runId);

        if (! $run->state()->requiresReconciliation()) {
            throw new PaymentsInvalidState(sprintf(
                'A settlement run in state "%s" has nothing to reconcile.',
                $run->state()->value,
            ));
        }

        foreach ($this->attempts->forRun($runId) as $attempt) {
            if ($attempt->state()->isTerminal()) {
                continue;
            }

            $outcome = $this->askProvider($attempt);
            match ($outcome) {
                GatewayOutcome::Succeeded => $this->confirmFromProvider($attempt),
                GatewayOutcome::Failed => $this->failFromProvider($attempt),
                GatewayOutcome::Processing => null,
                GatewayOutcome::Unknown => $this->escalate($attempt),
            };
        }

        return $this->refreshed($runId);
    }

    /**
     * Re-read a run so a caller sees the state reconciliation left behind
     * rather than the copy taken before it ran.
     *
     * A named method rather than an inline re-read, because inline the static
     * analyser memoises the earlier `findById($runId)` in the same body and
     * concludes the null branch is dead. It is not: the repository is not a
     * pure function, and another process can remove or replace the row between
     * the two reads. Here there is no prior call to narrow from, so the guard
     * is analysed — and enforced — as written.
     */
    private function refreshed(string $runId): SettlementRun
    {
        return $this->runs->findById($runId) ?? throw PaymentsNotFound::of('settlement run', $runId);
    }

    /**
     * Ask the provider, marking the attempt as being asked about.
     *
     * A gateway that does not implement {@see PayoutGateway} cannot be asked,
     * and that answers `Unknown` rather than anything more convenient. "We have
     * not integrated this provider's status endpoint" and "the transfer failed"
     * are not the same fact, and only one of them makes a retry safe.
     */
    private function askProvider(PayoutAttempt $attempt): GatewayOutcome
    {
        $gateway = $this->gateways->for($attempt->provider());

        if (! $gateway instanceof PayoutGateway) {
            return GatewayOutcome::Unknown;
        }

        $reference = $attempt->providerReference();
        if ($reference === null || trim($reference) === '') {
            // A `created` attempt that died before the transfer, or one whose
            // provider never gave a reference. There is nothing to look up, so
            // there is nothing to conclude.
            return GatewayOutcome::Unknown;
        }

        return $gateway->fetchTransferStatus($reference)->outcome();
    }

    /** The provider says the money went. Post what the crashed attempt never did. */
    private function confirmFromProvider(PayoutAttempt $attempt): bool
    {
        return $this->transactions->atomic(function () use ($attempt): bool {
            $run = $this->runs->findByIdForUpdate($attempt->settlementRunId());
            if ($run === null) {
                return false;
            }

            $now = $this->clock->now();
            $expected = $run->version();

            if ($run->state() === SettlementRunState::Unknown) {
                $run->beginReconciliation($now);
            }
            if ($run->state() !== SettlementRunState::Reconciling && $run->state() !== SettlementRunState::Processing) {
                return false;
            }

            if ($attempt->state() === PayoutAttemptState::Unknown) {
                $attempt->beginReconciliation($now);
            }
            $attempt->confirm($now);
            $this->attempts->update($attempt);

            // The postings the interrupted execution never got to. Keyed on the
            // run id exactly as the successful path would have been, so a
            // reconciled settlement is indistinguishable in the ledger from one
            // that never went wrong — which is the point.
            $this->ledger->commit(
                $this->ledger->newPosting($run->id(), TransactionType::Settlement, $run->settlementReference())
                    ->debit(LedgerAccount::MerchantPayable, $run->net())
                    ->credit(LedgerAccount::Payouts, $run->net()),
            );

            $run->markSucceeded($now);
            $this->runs->update($run, $expected);

            $this->audit(
                null,
                'finance.payout_reconciled_confirmed',
                $run,
                'succeeded',
                'Provider confirmed transfer '.($attempt->providerReference() ?? ''),
            );

            $this->notifier->settlementSucceeded($run);

            return true;
        });
    }

    /** The provider says it never happened. Only now is a retry safe. */
    private function failFromProvider(PayoutAttempt $attempt): bool
    {
        return $this->transactions->atomic(function () use ($attempt): bool {
            $run = $this->runs->findByIdForUpdate($attempt->settlementRunId());
            if ($run === null) {
                return false;
            }

            $now = $this->clock->now();
            $expected = $run->version();

            if ($run->state() === SettlementRunState::Unknown) {
                $run->beginReconciliation($now);
            }

            if ($attempt->state() === PayoutAttemptState::Unknown) {
                $attempt->beginReconciliation($now);
            }
            $attempt->reject('Provider confirmed the transfer did not happen.', $now);
            $this->attempts->update($attempt);

            $run->markFailed('Provider confirmed the transfer did not happen.', $now);
            $this->runs->update($run, $expected);
            // Safe because the provider *said so*. This release is the one that
            // would have been catastrophic to do on a guess.
            $this->runs->releaseLines($run);

            $this->audit(null, 'finance.payout_reconciled_failed', $run, 'failed', 'Provider confirmed no transfer.');

            $this->notifier->settlementFailed($run);

            return true;
        });
    }

    /** Nobody can tell. Hand it to a person, and stop. */
    private function escalate(PayoutAttempt $attempt): bool
    {
        $case = $this->transactions->atomic(function () use ($attempt): ?ReconciliationCase {
            $run = $this->runs->findByIdForUpdate($attempt->settlementRunId());
            if ($run === null) {
                return null;
            }

            $now = $this->clock->now();
            $expected = $run->version();

            if ($run->state() === SettlementRunState::Unknown) {
                $run->beginReconciliation($now);
                $run->markReconciliationRequired('The provider could not confirm what happened to this transfer.', $now);
                $this->runs->update($run, $expected);
            }

            if ($attempt->state() === PayoutAttemptState::Unknown || $attempt->state() === PayoutAttemptState::Created) {
                if ($attempt->state() === PayoutAttemptState::Created) {
                    $attempt->markUnknown('The process did not record an outcome for this transfer.', $now);
                }
                $attempt->beginReconciliation($now);
                $attempt->markReconciliationRequired('The provider could not confirm what happened.', $now);
                $this->attempts->update($attempt);
            }

            $this->audit(
                null,
                'finance.payout_reconciliation_required',
                $run,
                'unknown',
                'Provider could not confirm the transfer.',
            );

            return $this->open(
                DiscrepancyKind::PayoutStateMismatch,
                'payout_attempt',
                $attempt->id(),
                $attempt->amount(),
                new Money(0, $attempt->amount()->currency),
                'The provider could not confirm whether this transfer happened.',
            );
        });

        if ($case !== null) {
            // Null only when the run had disappeared, which the closure above
            // reports by returning early rather than by opening a case.
            $this->notifier->caseOpened($case);
        }

        return $case !== null;
    }

    /**
     * Open a case, or return the one already covering this subject.
     *
     * Always returns a case: either the new one or the existing one the partial
     * unique index refused to duplicate. Callers decide whether there is
     * anything to open at all; by the time they reach here, there is.
     *
     * Never opens a second case for a problem somebody is already working on —
     * a sweep running every fifteen minutes against a two-day investigation
     * would otherwise bury the queue it exists to fill.
     */
    private function open(
        DiscrepancyKind $kind,
        string $subjectType,
        string $subjectId,
        Money $expected,
        Money $observed,
        string $detail,
    ): ReconciliationCase {
        $case = ReconciliationCase::open(
            id: $this->cases->nextIdentity(),
            kind: $kind,
            subjectType: $subjectType,
            subjectId: $subjectId,
            expected: $expected,
            observed: $observed,
            detail: $detail,
            correlationId: CorrelationContext::forAudit(),
            now: $this->clock->now(),
        );

        return $this->cases->openOrReturnExisting($case);
    }

    private function audit(?string $actorId, string $action, SettlementRun $run, string $result, ?string $reason): void
    {
        $this->events->publish(new FinancialActionAudited(
            actorId: $actorId,
            auditAction: $action,
            subjectType: 'settlement_run',
            subjectId: $run->id(),
            amountMinor: $run->net()->minorUnits,
            currency: $run->currency(),
            reason: $reason,
            correlationId: CorrelationContext::forAudit(),
            idempotencyKey: $run->settlementReference(),
            beforeState: null,
            afterState: $run->state()->value,
            result: $result,
        ));
    }
}
