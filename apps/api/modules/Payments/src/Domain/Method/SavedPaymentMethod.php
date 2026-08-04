<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Method;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\CardFingerprint;

/**
 * A saved, tokenised payment method for a user. Only the provider token and
 * non-sensitive display data are stored (PCI-aware) — the raw card never
 * touches the platform. One method per user may be the default.
 */
final class SavedPaymentMethod
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly PaymentProvider $provider,
        private readonly CardFingerprint $card,
        private bool $default,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function save(
        string $id,
        string $userId,
        PaymentProvider $provider,
        CardFingerprint $card,
        bool $default,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $provider, $card, $default, $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        PaymentProvider $provider,
        CardFingerprint $card,
        bool $default,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $provider, $card, $default, $createdAt);
    }

    public function makeDefault(): void
    {
        $this->default = true;
    }

    public function unsetDefault(): void
    {
        $this->default = false;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function card(): CardFingerprint
    {
        return $this->card;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
