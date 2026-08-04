<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\ValueObject;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A tracked stock batch/lot — a quantity of a product received together, with
 * an optional expiry date. Enables batch tracking and expiry-date management
 * for perishable grocery lines.
 */
final readonly class Batch
{
    public function __construct(
        public string $batchNumber,
        public int $quantity,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $receivedAt = null,
    ) {
        if (trim($batchNumber) === '') {
            throw new InvalidArgumentException('Batch number cannot be empty.');
        }
        if ($quantity < 0) {
            throw new InvalidArgumentException('Batch quantity cannot be negative.');
        }
    }

    public function isExpired(DateTimeImmutable $asOf): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $asOf;
    }

    public function expiresWithin(DateTimeImmutable $asOf, int $days): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        $threshold = $asOf->modify(sprintf('+%d days', $days));

        return $this->expiresAt <= $threshold;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['batch_number'],
            (int) $data['quantity'],
            isset($data['expires_at'])
                ? new DateTimeImmutable((string) $data['expires_at']) : null,
            isset($data['received_at'])
                ? new DateTimeImmutable((string) $data['received_at']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_number' => $this->batchNumber,
            'quantity' => $this->quantity,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'received_at' => $this->receivedAt?->format(DATE_ATOM),
        ];
    }
}
