<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Input;

use EruoFood\Commerce\Domain\ValueObject\Address;

/** Validated input for creating/updating a store. */
final readonly class StoreInput
{
    public function __construct(
        public string $name,
        public ?string $description,
        public ?string $logo,
        public ?Address $address,
        public ?string $supportEmail,
        public ?string $supportPhone,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            logo: isset($data['logo']) && $data['logo'] !== '' ? (string) $data['logo'] : null,
            address: isset($data['address']) && is_array($data['address']) ? Address::fromArray($data['address']) : null,
            supportEmail: isset($data['support_email']) && $data['support_email'] !== '' ? (string) $data['support_email'] : null,
            supportPhone: isset($data['support_phone']) && $data['support_phone'] !== '' ? (string) $data['support_phone'] : null,
        );
    }
}
