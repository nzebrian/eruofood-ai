<?php

declare(strict_types=1);

namespace EruoFood\Payments\Contracts;

/**
 * The Payments module's PUBLIC contract for "this order is financially final".
 *
 * ## Why a contract rather than an event
 *
 * Payments must not listen for `Marketplace\Domain\Event\OrderDelivered`: that
 * would put a Marketplace class name in a Payments listener and make the
 * dependency point the wrong way. Publishing a port instead keeps the arrow
 * pointing at Payments, exactly as {@see PaymentInitiator} does for the
 * opposite direction — a fulfilling context depends on this interface and never
 * on a Payments internal.
 *
 * It also makes the call *synchronous and inside the caller's transaction*,
 * which matters: an order that is marked delivered and an accrual that records
 * what the merchant earned for it should not be able to disagree because a
 * queue dropped a message.
 *
 * ## What implementations must guarantee
 *
 * - **Idempotent per order.** Called twice for the same order id, the second
 *   call changes nothing. A unique index on `order_id` backs this, so a race
 *   between two callers is arbitrated by the database rather than by a
 *   read-then-write check.
 * - **Silent when there is nothing to accrue.** An order with no confirmed
 *   payment — cash on delivery, a fully-refunded order, a free order — records
 *   no accrual and raises nothing. The caller has done nothing wrong.
 * - **Never a reason to fail the caller.** Marking an order delivered is a
 *   fulfilment fact. If accrual is switched off, or the payment is not found,
 *   the delivery still happened.
 */
interface MerchantEarningsRecorder
{
    /**
     * Record what a merchant earned from an order that is now financially final.
     *
     * Returns the accrual id when one was written, and null when there was
     * nothing to accrue or the capability is switched off. Callers are not
     * expected to do anything with it; it exists so tests and the operator
     * report can tell "nothing to do" from "done".
     */
    public function recordSettledOrder(SettledOrder $order): ?string;
}
