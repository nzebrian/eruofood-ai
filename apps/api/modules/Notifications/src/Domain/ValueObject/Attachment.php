<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\ValueObject;

/** A chat message attachment (file or voice note) — a stored URL + metadata. */
final readonly class Attachment
{
    public function __construct(
        public string $url,
        public string $name,
        public string $mimeType,
        public int $sizeBytes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['url'],
            (string) ($data['name'] ?? 'file'),
            (string) ($data['mime_type'] ?? 'application/octet-stream'),
            (int) ($data['size_bytes'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
        ];
    }
}
