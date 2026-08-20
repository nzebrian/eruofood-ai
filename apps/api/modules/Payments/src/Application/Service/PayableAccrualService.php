<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Exception\PaymentsConflict;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagTarget;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Turns "this order is done" into "we owe this merchant this much".
 *
 * ## Where the money figures come from, and where they emphatically do not
 *
 * Not from the caller: {@see SettledOrder} carries three identifiers and no
 * amounts. Not from the commission calculator either — recomputing commission
 * at accrual time would read today's configured rate against a sale that
 * happened last month, and a rate change would silently rewrite history.
 *
 * They come from **the ledger's capture posting for that payment**. Those
 * entries were written at the moment the money arrived, they are protected by
 * an append-only trigger, and they are the same rows an auditor would use to
 * answer the question. If the ledger cannot answer, nothing is accrued.
 *
 * That is the whole of the F1 fix. There is no code path anywhere in this
 * service that reads an amount from a request.
 *
 * ## The report-only cycle
 *
 * With `settlement.accrual` on and `settlement.accrual_posting` off, accruals
 * are written and the ledger is not touched. Finance can compare the platform's
 * totals against their own for a full cycle while no money is at stake, and a
 * report-only accrual cannot be settled — see {@see PayableAccrual::isSettleable()}.
 *
 * ## Failure policy
 *
 * Marking an order delivered is a fulfilment fact and must not be undone
 * because settlement is misconfigured. Every "cannot accrue" path here returns
 * null rather than throwing. The one exception is a genuine database error,
 * which propagates — a silent swallow there would lose the accrual for ever
 * with nothing to indicate it.
 */
final readonly class PayableAccrualService implements MerchantEarningsRecorder
{
    public const FLAG_ACCRUAL = 'settlement.accrual';

    public const FLAG_POSTING = 'settlement.accrual_posting';

    public function __construct(
        private PayableAccrualRepository $accruals,
        private PaymentRepository $payments,
        private LedgerRepository $ledger,
        private LedgerService $ledgerService,
        private TransactionManager $transactions,
        private FlagEvaluator $flags,
        private Clock $clock,
    ) {
    }

    public function recordSettledOrder(SettledOrder $order): ?string
    {
        $target = FlagTarget::of(merchantId: $order->merchantId);

        if (! $this->flags->isEnabled(self::FLAG_ACCRUAL, $target)) {
            return null;
        }

        // Fast path only. The unique index below is the actual guarantee — two
        // concurrent deliveries of the same order both find nothing here.
        $existing = $this->accruals->findEarningForOrder($order->orderId);
        if ($existing !== null) {
            return $existing->id();
        }

        $payment = $this->payments->findCapturedForOrder($order->orderId);
        if ($payment === null || $payment->status() !== PaymentStatus::Succeeded) {
            // Cash on delivery, a free order, an order whose payment failed.
            // Nothing was captured, so nothing is owed.
            return null;
        }

        $capture = $this->captureFigures($payment);
        if ($capture === null) {
            // A succeeded payment with no capture posting should not exist. It
            // is left for the payment-vs-accrual reconciler to raise rather
            // than guessed at here.
            return null;
        }

        $postLedger = $this->flags->isEnabled(self::FLAG_POSTING, $target);

        $accrual = PayableAccrual::accrue(
            id: $this->accruals->nextIdentity(),
            merchantType: $order->merchantType,
            merchantId: $order->merchantId,
            orderId: $order->orderId,
            paymentId: $payment->id(),
            gross: $capture['gross'],
            commission: $capture['commission'],
            fee: $capture['fee'],
            commissionRateBps: $this->effectiveRateBps($capture['gross'], $capture['commission']),
            ledgerPosted: $postLedger,
            correlationId: CorrelationContext::forAudit(),
            now: $this->clock->now(),
        );

        try {
            $this->transactions->atomic(function () use ($accrual, $postLedger): void {
                $this->accruals->insert($accrual);

                if ($postLedger && $accrual->net()->minorUnits > 0) {
                    // Escrow stops being "money we hold" and becomes "money we
                    // owe this merchant". Inside the same transaction as the
                    // accrual row: a ledger movement without its accrual, or an
                    // accrual claiming a movement that never happened, are both
                    // states nothing could later untangle.
                    $this->ledgerService->commit(
                        $this->ledgerService
                            ->newPosting($accrual->id(), TransactionType::Settlement, $accrual->orderId())
                            ->debit(LedgerAccount::Escrow, $accrual->net())
                            ->credit(LedgerAccount::MerchantPayable, $accrual->net()),
                    );
                }
            });
        } catch (PaymentsConflict) {
            // Another worker won the race for this order. The row we wanted to
            // exist does, which is success.
            $winner = $this->accruals->findEarningForOrder($order->orderId);

            return $winner?->id();
        }

        return $accrual->id();
    }

    /**
     * Record that a refund has reduced what a merchant is owed.
     *
     * Called from within Payments after a refund completes, so it takes ids
     * rather than a published contract type. Silent when the order was never
     * accrued: a refund on an order that has not been delivered simply reduces
     * the earning that will later be computed, because the capture figures the
     * accrual reads are the ones the ledger holds.
     */
    public function recordRefund(string $orderId, string $refundId, Money $refundedGross): ?string
    {
        $earning = $this->accruals->findEarningForOrder($orderId);
        if ($earning === null) {
            return null;
        }

        if ($refundedGross->minorUnits <= 0 || $refundedGross->currency !== $earning->net()->currency) {
            return null;
        }

        $adjustment = PayableAccrual::refundAdjustment(
            id: $this->accruals->nextIdentity(),
            merchantType: $earning->merchantType(),
            merchantId: $earning->merchantId(),
            orderId: $orderId,
            paymentId: $earning->paymentId(),
            refundId: $refundId,
            refundedGross: $refundedGross,
            ledgerPosted: $earning->ledgerPosted(),
            correlationId: CorrelationContext::forAudit(),
            now: $this->clock->now(),
        );

        try {
            $this->transactions->atomic(function () use ($adjustment, $earning): void {
                $this->accruals->insert($adjustment);

                if ($earning->ledgerPosted()) {
                    // The mirror of the accrual posting: what we owe the
                    // merchant goes back to escrow, where the refund path can
                    // take it out again.
                    $amount = new Money(abs($adjustment->net()->minorUnits), $adjustment->net()->currency);
                    $this->ledgerService->commit(
                        $this->ledgerService
                            ->newPosting($adjustment->id(), TransactionType::Refund, $adjustment->orderId())
                            ->debit(LedgerAccount::MerchantPayable, $amount)
                            ->credit(LedgerAccount::Escrow, $amount),
                    );
                }
            });
        } catch (PaymentsConflict) {
            // The refund was already applied to the payable — a duplicate
            // webhook, or a retry. Nothing further to do.
            return null;
        }

        return $adjustment->id();
    }

    /**
     * Read gross, commission and fee back out of the capture posting.
     *
     * The posting is `Cash` debit = gross, `Escrow` credit = net, plus optional
     * `Commission` and `Fees` credits. Only entries of type `Payment` are
     * considered, so a later refund or settlement posting under the same
     * correlation cannot contaminate the figures.
     *
     * @return array{gross: Money, commission: Money, fee: Money}|null
     */
    private function captureFigures(Payment $payment): ?array
    {
        $entries = $this->ledger->forCorrelation($payment->id());

        $currency = $payment->amount()->currency;
        $gross = null;
        $commission = new Money(0, $currency);
        $fee = new Money(0, $currency);

        foreach ($entries as $entry) {
            if ($entry->type !== TransactionType::Payment) {
                continue;
            }

            if ($entry->account === LedgerAccount::Cash && $entry->direction === TransactionDirection::Debit) {
                $gross = $entry->amount;
            }
            if ($entry->account === LedgerAccount::Commission && $entry->direction === TransactionDirection::Credit) {
                $commission = $commission->add($entry->amount);
            }
            if ($entry->account === LedgerAccount::Fees && $entry->direction === TransactionDirection::Credit) {
                $fee = $fee->add($entry->amount);
            }
        }

        if ($gross === null || $gross->minorUnits <= 0) {
            return null;
        }

        return ['gross' => $gross, 'commission' => $commission, 'fee' => $fee];
    }

    /**
     * The commission rate this sale actually bore, in basis points.
     *
     * Derived from the amounts rather than read from configuration, so the
     * snapshot describes what happened rather than what is currently
     * configured. The two agree today; they will not after the first rate
     * change, and this is the one that stays true.
     */
    private function effectiveRateBps(Money $gross, Money $commission): int
    {
        if ($gross->minorUnits <= 0) {
            return 0;
        }

        return (int) round($commission->minorUnits * 10000 / $gross->minorUnits);
    }
}
