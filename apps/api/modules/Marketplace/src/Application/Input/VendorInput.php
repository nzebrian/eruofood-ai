<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Input;

use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;

/** Validated input for registering / updating a vendor. */
final readonly class VendorInput
{
    public function __construct(
        public string $name,
        public VendorType $type,
        public string $category,
        public ?string $description,
        public ContactInfo $contact,
        public Address $address,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            type: VendorType::from((string) $data['type']),
            category: (string) ($data['category'] ?? 'general'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            contact: ContactInfo::fromArray((array) ($data['contact'] ?? [])),
            address: Address::fromArray((array) ($data['address'] ?? [])),
        );
    }
}
