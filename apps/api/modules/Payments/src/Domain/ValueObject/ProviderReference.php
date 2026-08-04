<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\ValueObject;

use EruoFood\Payments\Domain\Enum\PaymentProvider;

/**
 * A provider-side reference for a payment (e.g. Paystack transaction reference),
 * paired with the provider it belongs to. Immutable; used to reconcile a local
 * Payment with the gateway and to verify webhooks.
 */
final readonly class ProviderReference
{
    public function __construct(
        public PaymentProvider $provider,
        public string $reference,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(PaymentProvider::from((string) $data['provider']), (string) $data['reference']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['provider' => $this->provider->value, 'reference' => $this->reference];
    }
}
