<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\ValueObject;

/**
 * A postal/shipping address. Deliberately simple and self-contained (the
 * commerce context references no other context's address type).
 */
final readonly class Address
{
    public function __construct(
        public string $line1,
        public ?string $line2,
        public string $city,
        public string $state,
        public ?string $postcode,
        public string $country = 'NG',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['line1'] ?? ''),
            isset($data['line2']) ? (string) $data['line2'] : null,
            (string) ($data['city'] ?? ''),
            (string) ($data['state'] ?? ''),
            isset($data['postcode']) ? (string) $data['postcode'] : null,
            (string) ($data['country'] ?? 'NG'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
        ];
    }
}
