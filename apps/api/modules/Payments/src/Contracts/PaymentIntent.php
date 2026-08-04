<?php

declare(strict_types=1);

namespace EruoFood\Payments\Contracts;

/**
 * The handle returned to another context after initiating a payment: the local
 * payment id/reference, its current status, and the hosted-checkout URL to
 * redirect the payer to (when the provider requires it).
 */
final readonly class PaymentIntent
{
    public function __construct(
        public string $paymentId,
        public string $reference,
        public string $status,
        public ?string $authorizationUrl,
        public string $provider,
    ) {
    }
}
