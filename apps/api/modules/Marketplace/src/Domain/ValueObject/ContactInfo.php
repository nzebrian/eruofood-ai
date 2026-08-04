<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

/** A vendor's public contact details. */
final readonly class ContactInfo
{
    public function __construct(
        public string $phone,
        public ?string $email = null,
        public ?string $whatsapp = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            phone: (string) ($data['phone'] ?? ''),
            email: isset($data['email']) && $data['email'] !== '' ? (string) $data['email'] : null,
            whatsapp: isset($data['whatsapp']) && $data['whatsapp'] !== '' ? (string) $data['whatsapp'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['phone' => $this->phone, 'email' => $this->email, 'whatsapp' => $this->whatsapp];
    }
}
