<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Notification;

use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Settlement\Settlement;
use Psr\Log\LoggerInterface;

/**
 * The default notifier — writes a structured audit-log line for each financial
 * event (payment success/failure, refund, settlement, wallet alert). A real
 * Notifications context can replace it behind the {@see PaymentNotifier} port.
 */
final readonly class LoggingPaymentNotifier implements PaymentNotifier
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function paymentSucceeded(Payment $payment): void
    {
        $this->log->info('payments.notify.payment_succeeded', ['payment_id' => $payment->id(), 'amount_minor' => $payment->amount()->minorUnits]);
    }

    public function paymentFailed(Payment $payment): void
    {
        $this->log->warning('payments.notify.payment_failed', ['payment_id' => $payment->id(), 'reason' => $payment->failureReason()]);
    }

    public function refundCompleted(Refund $refund): void
    {
        $this->log->info('payments.notify.refund_completed', ['refund_id' => $refund->id(), 'amount_minor' => $refund->amount()->minorUnits]);
    }

    public function settlementCompleted(Settlement $settlement): void
    {
        $this->log->info('payments.notify.settlement_completed', ['settlement_id' => $settlement->id(), 'net_minor' => $settlement->net()->minorUnits]);
    }

    public function walletLowBalance(string $ownerType, string $ownerId, int $balanceMinor): void
    {
        $this->log->notice('payments.notify.wallet_low_balance', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'balance_minor' => $balanceMinor]);
    }
}
