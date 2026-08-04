<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

/** A postal/physical address with an optional geocoded location. */
final readonly class Address
{
    public function __construct(
        public string $line,
        public string $city,
        public string $state,
        public ?GeoLocation $location = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            line: (string) ($data['line'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            location: isset($data['location']) && is_array($data['location'])
                ? GeoLocation::fromArray($data['location'])
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'city' => $this->city,
            'state' => $this->state,
            'location' => $this->location?->toArray(),
        ];
    }
}
