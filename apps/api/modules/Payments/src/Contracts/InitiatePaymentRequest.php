<?php

declare(strict_types=1);

namespace EruoFood\Payments\Contracts;

/**
 * A request another bounded context makes to the Payments engine to begin
 * collecting money — e.g. Commerce/Marketplace at checkout. The caller passes an
 * **opaque** order reference and the amount in minor units; Payments does not
 * import or depend on the order's model. Splits (payee → amount minor) are
 * optional for marketplace payouts.
 */
final readonly class InitiatePaymentRequest
{
    /**
     * @param list<array{payee_type: string, payee_id: string, amount_minor: int}> $splits
     */
    public function __construct(
        public string $payerUserId,
        public string $customerEmail,
        public int $amountMinor,
        public ?string $orderId = null,
        public string $currency = 'NGN',
        public string $methodType = 'card',
        public ?string $provider = null,
        public ?string $idempotencyKey = null,
        public array $splits = [],
    ) {
    }
}
