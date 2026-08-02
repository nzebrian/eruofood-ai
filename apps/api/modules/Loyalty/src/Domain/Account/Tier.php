<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

/**
 * A membership tier: a key, a display name, the lifetime-points threshold to
 * reach it, and an earn multiplier applied to points earned while in it. Tiers
 * are defined in config and resolved by {@see TierPolicy}.
 */
final readonly class Tier
{
    public function __construct(
        public string $key,
        public string $name,
        public int $threshold,
        public float $earnMultiplier,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['key'] ?? 'bronze'),
            (string) ($data['name'] ?? 'Bronze'),
            (int) ($data['threshold'] ?? 0),
            (float) ($data['earn_multiplier'] ?? 1.0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'threshold' => $this->threshold,
            'earn_multiplier' => $this->earnMultiplier,
        ];
    }
}
