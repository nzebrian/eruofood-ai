<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Config;

use DateTimeImmutable;

/**
 * A named feature toggle. Distinct from a {@see Setting} because it is a pure
 * on/off gate that other contexts read to guard rollout of functionality.
 */
final class FeatureFlag
{
    private function __construct(
        private readonly string $key,
        private bool $enabled,
        private ?string $description,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function define(string $key, bool $enabled, ?string $description, DateTimeImmutable $now): self
    {
        return new self($key, $enabled, $description, $now);
    }

    public static function reconstitute(string $key, bool $enabled, ?string $description, DateTimeImmutable $updatedAt): self
    {
        return new self($key, $enabled, $description, $updatedAt);
    }

    public function enable(DateTimeImmutable $now): void
    {
        $this->enabled = true;
        $this->updatedAt = $now;
    }

    public function disable(DateTimeImmutable $now): void
    {
        $this->enabled = false;
        $this->updatedAt = $now;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
