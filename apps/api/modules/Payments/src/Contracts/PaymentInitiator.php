<?php

declare(strict_types=1);

namespace EruoFood\Payments\Contracts;

/**
 * The Payments module's PUBLIC contract for other bounded contexts.
 *
 * Modules like Commerce and Marketplace depend on this interface (never on the
 * Payments internals) to start collecting money for an order. Payments reports
 * the outcome back through **domain events** (PaymentSucceeded / PaymentFailed /
 * RefundCompleted), keeping the two sides decoupled — the Order module and the
 * Payments module never reference each other's models.
 */
interface PaymentInitiator
{
    public function initiate(InitiatePaymentRequest $request): PaymentIntent;
}
