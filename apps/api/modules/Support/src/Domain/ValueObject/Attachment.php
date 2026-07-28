<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\ValueObject;

/** A file attached to a ticket message (stored elsewhere; referenced by URL). */
final readonly class Attachment
{
    public function __construct(
        public string $url,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['url'] ?? ''),
            (string) ($data['filename'] ?? ''),
            (string) ($data['mime_type'] ?? 'application/octet-stream'),
            (int) ($data['size_bytes'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
        ];
    }
}
