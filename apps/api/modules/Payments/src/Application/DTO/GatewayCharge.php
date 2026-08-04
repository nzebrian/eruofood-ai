<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A request to a payment gateway to initialize/authorize a charge. */
final readonly class GatewayCharge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $reference,
        public Money $amount,
        public string $customerEmail,
        public PaymentMethodType $methodType,
        public ?string $savedMethodToken = null,
        public array $metadata = [],
    ) {
    }
}
