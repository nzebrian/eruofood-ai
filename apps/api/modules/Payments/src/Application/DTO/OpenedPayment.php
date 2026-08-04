<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

use EruoFood\Payments\Domain\Payment\Payment;

/**
 * The result of opening a payment: the Payment aggregate plus the provider's
 * hosted-checkout URL (when a redirect is required). Lets the service stay a
 * readonly, stateless orchestrator.
 */
final readonly class OpenedPayment
{
    public function __construct(
        public Payment $payment,
        public ?string $authorizationUrl,
    ) {
    }
}
