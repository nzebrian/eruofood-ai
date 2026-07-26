<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

/** A physical branch/outlet of a vendor. */
final readonly class Branch
{
    public function __construct(
        public string $id,
        public string $name,
        public Address $address,
        public ?string $phone = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            address: Address::fromArray((array) ($data['address'] ?? [])),
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address->toArray(),
            'phone' => $this->phone,
        ];
    }
}
