<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Config;

use DateTimeImmutable;

/**
 * A single system-configuration value, addressed by a dotted key and bucketed
 * into a group (app, ai, payment, notification, email, sms, regional, …). The
 * value is stored as a string; JSON payloads are encoded by the caller. A flag
 * marks secret values so the API can redact them.
 */
final class Setting
{
    private function __construct(
        private readonly string $key,
        private readonly string $group,
        private string $value,
        private bool $secret,
        private ?string $description,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function define(
        string $key,
        string $group,
        string $value,
        bool $secret,
        ?string $description,
        DateTimeImmutable $now,
    ): self {
        return new self($key, $group, $value, $secret, $description, $now);
    }

    public static function reconstitute(
        string $key,
        string $group,
        string $value,
        bool $secret,
        ?string $description,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($key, $group, $value, $secret, $description, $updatedAt);
    }

    public function changeValue(string $value, DateTimeImmutable $now): void
    {
        $this->value = $value;
        $this->updatedAt = $now;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    /** The value with secrets redacted, for API responses. */
    public function displayValue(): string
    {
        return $this->secret ? '••••••••' : $this->value;
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
