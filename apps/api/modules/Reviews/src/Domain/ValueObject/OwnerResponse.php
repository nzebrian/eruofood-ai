<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\ValueObject;

use DateTimeImmutable;

/** A subject owner's (vendor/restaurant) public response to a review. */
final readonly class OwnerResponse
{
    public function __construct(
        public string $responderId,
        public string $body,
        public DateTimeImmutable $respondedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['responder_id'] ?? ''),
            (string) ($data['body'] ?? ''),
            new DateTimeImmutable((string) ($data['responded_at'] ?? 'now')),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'responder_id' => $this->responderId,
            'body' => $this->body,
            'responded_at' => $this->respondedAt->format(DATE_ATOM),
        ];
    }
}
