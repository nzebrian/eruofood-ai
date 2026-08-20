<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * The reference a provider sees for a settlement, and the platform's own handle
 * on it.
 *
 * ## Deterministic
 *
 * Built from the merchant and the window, so recomputing the same settlement
 * produces the same reference. Combined with the unique index on the column,
 * that means a second attempt to create the same run collides at the database
 * rather than opening a second one with a different name — and, more usefully,
 * an operator staring at a provider dashboard can match a transfer back to a
 * window without a lookup table.
 *
 * ## Provider-safe
 *
 * Uppercase alphanumerics and hyphens only, bounded at 64 characters. Providers
 * differ on what they accept in a transfer narration and several silently
 * truncate; a reference that survives being truncated to its first 32 characters
 * is still unique here, because the hash comes before the readable part.
 */
final readonly class SettlementReference
{
    private const PREFIX = 'STL';

    private function __construct(public string $value)
    {
    }

    public static function for(
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): self {
        $material = implode('|', [
            $merchantType,
            $merchantId,
            strtoupper($currency),
            $windowStart->format('Y-m-d\TH:i:s'),
            $windowEnd->format('Y-m-d\TH:i:s'),
        ]);

        // 16 hex characters — 64 bits. A collision would need two different
        // merchants or windows to hash alike, which at platform scale is not a
        // risk worth a longer reference that a provider might truncate.
        $digest = strtoupper(substr(hash('sha256', $material), 0, 16));

        return new self(sprintf('%s-%s-%s', self::PREFIX, $digest, $windowStart->format('Ymd')));
    }

    /** Rebuild from storage, refusing anything a provider would not accept. */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z0-9-]{1,64}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Settlement reference '{$value}' must be uppercase alphanumerics and hyphens, at most 64 characters.",
            );
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
