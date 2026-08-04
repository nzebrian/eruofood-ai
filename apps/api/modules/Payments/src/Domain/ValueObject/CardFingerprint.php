<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\ValueObject;

/**
 * A tokenised, PCI-safe reference to a saved card. Only non-sensitive display
 * data (brand, last 4, expiry) is stored locally; the provider token is the
 * only thing that can charge the card, and full PAN/CVV never touch the system.
 */
final readonly class CardFingerprint
{
    public function __construct(
        public string $token,
        public string $brand,
        public string $last4,
        public int $expiryMonth,
        public int $expiryYear,
    ) {
    }

    public function masked(): string
    {
        return sprintf('%s •••• %s', ucfirst($this->brand), $this->last4);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['token'],
            (string) ($data['brand'] ?? 'card'),
            (string) ($data['last4'] ?? '0000'),
            (int) ($data['expiry_month'] ?? 1),
            (int) ($data['expiry_year'] ?? 2030),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expiry_month' => $this->expiryMonth,
            'expiry_year' => $this->expiryYear,
        ];
    }
}
