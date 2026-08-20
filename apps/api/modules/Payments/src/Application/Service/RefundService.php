<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Event\RefundCompleted;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Payment\RefundRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Full and partial refunds. Refunds go back through the same provider that
 * captured the payment, adjust the payment's refunded total, post the ledger,
 * and publish {@see RefundCompleted} so the originating context (e.g. Commerce's
 * returns flow) can react — without Payments calling it directly.
 *
 * A refund moves real money out, so the request is deliberately split into three
 * phases rather than one long operation:
 *
 *  1. **Reserve** (transaction, payment row locked) — check the refundable
 *     balance and write a `pending` refund that claims it.
 *  2. **Call the provider** — outside any transaction. Network calls must never
 *     run inside one: they hold locks for the length of a round trip and their
 *     effect cannot be rolled back.
 *  3. **Settle** (transaction) — mark the refund completed and post the ledger,
 *     or mark it failed, which releases the reservation.
 *
 * The reservation is what makes concurrent refunds safe. Two requests for the
 * same payment serialise on the row lock; the second sees the first's pending
 * claim in the refundable calculation and is refused, so the same money cannot
 * be sent twice.
 */
final readonly class RefundService
{
    public function __construct(
        private RefundRepository $refunds,
        private PaymentRepository $payments,
        private PaymentGatewayFactory $gateways,
        private LedgerService $ledger,
        private PaymentNotifier $notifier,
        private EventBus $events,
        private TransactionManager $transactions,
        private PayableAccrualService $accruals,
        private LoggerInterface $log,
        private string $currency,
    ) {
    }

    public function request(string $paymentId, ?int $amountMinor, string $reason, string $actorUserId, bool $actorIsAdmin): Refund
    {
        [$refund, $payment] = $this->reserve($paymentId, $amountMinor, $reason, $actorUserId, $actorIsAdmin);

        $providerRef = $payment->providerReference();
        $result = $providerRef !== null
            ? $this->gateways->for($payment->provider())->refund($providerRef->reference, $refund->amount())
            : $this->gateways->for(PaymentProvider::Wallet)->refund($payment->reference(), $refund->amount());

        return $result->success
            ? $this->settle($refund)
            : $this->release($refund);
    }

    /**
     * Phase 1 — claim the amount against the payment under an exclusive lock.
     *
     * @return array{0: Refund, 1: Payment}
     */
    private function reserve(
        string $paymentId,
        ?int $amountMinor,
        string $reason,
        string $actorUserId,
        bool $actorIsAdmin,
    ): array {
        return $this->transactions->atomic(function () use ($paymentId, $amountMinor, $reason, $actorUserId, $actorIsAdmin): array {
            $payment = $this->payments->findByIdForUpdate($paymentId)
                ?? throw PaymentsNotFound::of('payment', $paymentId);

            if (! $actorIsAdmin && ! $payment->isForPayer($actorUserId)) {
                throw new PaymentsNotAuthorized();
            }
            if (! $payment->status()->isCaptured()) {
                throw new PaymentsInvalidState('Only a captured payment can be refunded.');
            }

            // Refundable is the captured amount less everything already claimed —
            // completed refunds *and* refunds still awaiting a provider answer.
            $reserved = $this->refunds->reservedMinorFor($paymentId);
            $refundableMinor = $payment->amount()->minorUnits - $reserved;

            $requestedMinor = $amountMinor ?? $refundableMinor;
            if ($requestedMinor <= 0 || $requestedMinor > $refundableMinor) {
                throw new PaymentsInvalidState('Refund amount exceeds the refundable balance.');
            }

            $amount = new Money($requestedMinor, $this->currency);
            $refund = Refund::open(
                $this->refunds->nextIdentity(),
                $payment->id(),
                $payment->orderId(),
                $amount,
                $requestedMinor < $payment->amount()->minorUnits,
                $reason,
                new DateTimeImmutable(),
            );
            $this->refunds->save($refund);

            return [$refund, $payment];
        });
    }

    /** Phase 3a — the provider paid out: complete the refund and post the ledger. */
    private function settle(Refund $refund): Refund
    {
        [$completed, $event] = $this->transactions->atomic(function () use ($refund): array {
            $payment = $this->payments->findByIdForUpdate($refund->paymentId())
                ?? throw PaymentsNotFound::of('payment', $refund->paymentId());

            $now = new DateTimeImmutable();
            $fully = $payment->applyRefund($refund->amount(), $now);
            $refund->complete($now);

            $this->payments->save($payment);
            $this->refunds->save($refund);
            $this->ledger->recordRefund($payment->id(), $refund->id(), $refund->amount());

            return [$refund, new RefundCompleted(
                $refund->id(),
                $payment->id(),
                $payment->orderId(),
                $refund->amount()->minorUnits,
                $refund->amount()->currency,
                ! $fully || $refund->isPartial(),
            )];
        });

        // A refund reduces what the merchant is owed, and the reduction is a
        // second accrual row rather than an edit to the first — so it can only
        // be written once the refund itself has committed.
        //
        // Outside the transaction, and swallowing its own failure, for the same
        // reason the notifier is: the customer has their money back, and that
        // must not be undone because settlement bookkeeping had a problem. A
        // missed adjustment is visible (the payable/ledger reconciler compares
        // exactly these two numbers) and recoverable; an un-refunded customer
        // is neither.
        if ($completed->orderId() !== null) {
            try {
                $this->accruals->recordRefund($completed->orderId(), $completed->id(), $completed->amount());
            } catch (Throwable $e) {
                $this->log->error('payments.refund.payable_adjustment_failed', [
                    'refund_id' => $completed->id(),
                    'order_id' => $completed->orderId(),
                    'exception' => $e::class,
                ]);
            }
        }

        // Side effects only after the transaction commits — a subscriber must not
        // react to a refund a rollback is about to erase.
        $this->notifier->refundCompleted($completed);
        $this->events->publish($event);

        return $completed;
    }

    /** Phase 3b — the provider refused: fail the refund, releasing its reservation. */
    private function release(Refund $refund): Refund
    {
        return $this->transactions->atomic(function () use ($refund): Refund {
            $refund->fail(new DateTimeImmutable());
            $this->refunds->save($refund);

            return $refund;
        });
    }

    /** @return list<Refund> */
    public function forPayment(string $paymentId): array
    {
        return $this->refunds->forPayment($paymentId);
    }

    /** @return Paginated<Refund> */
    public function all(int $page, int $perPage): Paginated
    {
        return $this->refunds->all($page, $perPage);
    }
}
