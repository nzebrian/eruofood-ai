<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Settlement\Settlement;

/**
 * Sends payment/financial notifications (success, failure, refund, settlement,
 * wallet alerts). A port so the Notifications context (or email/SMS/push) can
 * be plugged in; the default is a no-op logger.
 */
interface PaymentNotifier
{
    public function paymentSucceeded(Payment $payment): void;

    public function paymentFailed(Payment $payment): void;

    public function refundCompleted(Refund $refund): void;

    public function settlementCompleted(Settlement $settlement): void;

    public function walletLowBalance(string $ownerType, string $ownerId, int $balanceMinor): void;
}
