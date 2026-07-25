<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A food's name in a Nigerian language/culture (e.g. "Iyan" in Yoruba for
 * pounded yam). Supports the "multiple local names" requirement.
 */
final readonly class LocalName
{
    public function __construct(
        public string $name,
        public string $language,
    ) {
        if (trim($name) === '' || trim($language) === '') {
            throw new InvalidArgumentException('Local name and language are required.');
        }
    }

    /** @return array{name: string, language: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'language' => $this->language];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['name'] ?? ''), (string) ($data['language'] ?? ''));
    }
}
