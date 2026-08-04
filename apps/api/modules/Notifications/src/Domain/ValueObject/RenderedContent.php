<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\ValueObject;

/** The rendered subject + body of a notification for a given channel/locale. */
final readonly class RenderedContent
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['subject'] ?? ''), (string) ($data['body'] ?? ''));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'body' => $this->body];
    }
}
