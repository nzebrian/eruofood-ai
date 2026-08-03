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
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Payment\RefundRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Full and partial refunds. Refunds go back through the same provider that
 * captured the payment, adjust the payment's refunded total, post the ledger,
 * and publish {@see RefundCompleted} so the originating context (e.g. Commerce's
 * returns flow) can react — without Payments calling it directly.
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
        private string $currency,
    ) {
    }

    public function request(string $paymentId, ?int $amountMinor, string $reason, string $actorUserId, bool $actorIsAdmin): Refund
    {
        $payment = $this->payments->findById($paymentId) ?? throw PaymentsNotFound::of('payment', $paymentId);
        if (! $actorIsAdmin && ! $payment->isForPayer($actorUserId)) {
            throw new PaymentsNotAuthorized();
        }
        if (! $payment->status()->isCaptured()) {
            throw new PaymentsInvalidState('Only a captured payment can be refunded.');
        }

        $refundable = $payment->refundableAmount();
        $amount = $amountMinor !== null ? new Money($amountMinor, $this->currency) : $refundable;
        if ($amount->minorUnits <= 0 || $amount->minorUnits > $refundable->minorUnits) {
            throw new PaymentsInvalidState('Refund amount exceeds the refundable balance.');
        }
        $partial = $amount->minorUnits < $payment->amount()->minorUnits;

        $refund = Refund::open(
            $this->refunds->nextIdentity(),
            $payment->id(),
            $payment->orderId(),
            $amount,
            $partial,
            $reason,
            new DateTimeImmutable(),
        );

        $providerRef = $payment->providerReference();
        $result = $providerRef !== null
            ? $this->gateways->for($payment->provider())->refund($providerRef->reference, $amount)
            : $this->gateways->for(PaymentProvider::Wallet)->refund($payment->reference(), $amount);

        $now = new DateTimeImmutable();
        if ($result->success) {
            $fully = $payment->applyRefund($amount, $now);
            $refund->complete($now);
            $this->payments->save($payment);
            $this->ledger->recordRefund($payment->id(), $refund->id(), $amount);
            $this->refunds->save($refund);
            $this->notifier->refundCompleted($refund);
            $this->events->publish(new RefundCompleted(
                $refund->id(),
                $payment->id(),
                $payment->orderId(),
                $amount->minorUnits,
                $amount->currency,
                ! $fully || $partial,
            ));
        } else {
            $refund->fail($now);
            $this->refunds->save($refund);
        }

        return $refund;
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
