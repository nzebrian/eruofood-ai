<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Input;

use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Shared\Domain\ValueObject\Money;

/** Validated input for initiating a payment (from the HTTP layer or the contract). */
final readonly class InitiatePaymentInput
{
    /**
     * @param list<PaymentSplit> $splits
     */
    public function __construct(
        public string $payerUserId,
        public string $customerEmail,
        public Money $amount,
        public ?string $orderId,
        public PaymentMethodType $methodType,
        public ?PaymentProvider $provider,
        public ?string $idempotencyKey,
        public array $splits,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        $splits = array_map(
            static fn (array $s): PaymentSplit => PaymentSplit::fromArray($s, $currency),
            $data['splits'] ?? [],
        );

        return new self(
            payerUserId: (string) $data['payer_user_id'],
            customerEmail: (string) ($data['customer_email'] ?? ''),
            amount: new Money((int) $data['amount_minor'], $currency),
            orderId: isset($data['order_id']) && $data['order_id'] !== '' ? (string) $data['order_id'] : null,
            methodType: PaymentMethodType::from((string) ($data['method_type'] ?? 'card')),
            provider: isset($data['provider']) && $data['provider'] !== '' ? PaymentProvider::from((string) $data['provider']) : null,
            idempotencyKey: isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? (string) $data['idempotency_key'] : null,
            splits: array_values($splits),
        );
    }
}
