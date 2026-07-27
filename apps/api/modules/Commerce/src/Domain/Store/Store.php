<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Store;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A seller's storefront on the marketplace — the aggregate root for a vendor's
 * presence. Owns the profile and the verification lifecycle: only a verified
 * store may publish products and trade.
 */
final class Store
{
    private function __construct(
        private readonly string $id,
        private readonly string $ownerUserId,
        private string $name,
        private Slug $slug,
        private bool $verified,
        private ?string $description,
        private ?string $logo,
        private ?Address $address,
        private ?string $supportEmail,
        private ?string $supportPhone,
        private float $ratingAverage,
        private int $ratingCount,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(
        string $id,
        string $ownerUserId,
        string $name,
        Slug $slug,
        DateTimeImmutable $now,
        bool $autoVerify = false,
    ): self {
        return new self(
            $id, $ownerUserId, $name, $slug, $autoVerify, null, null, null,
            null, null, 0.0, 0, $now,
        );
    }

    public static function reconstitute(
        string $id,
        string $ownerUserId,
        string $name,
        Slug $slug,
        bool $verified,
        ?string $description,
        ?string $logo,
        ?Address $address,
        ?string $supportEmail,
        ?string $supportPhone,
        float $ratingAverage,
        int $ratingCount,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id, $ownerUserId, $name, $slug, $verified, $description, $logo,
            $address, $supportEmail, $supportPhone, $ratingAverage, $ratingCount, $createdAt,
        );
    }

    public function verify(): void
    {
        $this->verified = true;
    }

    public function suspend(): void
    {
        $this->verified = false;
    }

    public function updateProfile(
        string $name,
        ?string $description,
        ?string $logo,
        ?Address $address,
        ?string $supportEmail,
        ?string $supportPhone,
    ): void {
        $this->name = $name;
        $this->description = $description;
        $this->logo = $logo;
        $this->address = $address;
        $this->supportEmail = $supportEmail;
        $this->supportPhone = $supportPhone;
    }

    public function applyRatingSummary(float $average, int $count): void
    {
        $this->ratingAverage = round($average, 2);
        $this->ratingCount = $count;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerUserId === $userId;
    }

    public function canTrade(): bool
    {
        return $this->verified;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function logo(): ?string
    {
        return $this->logo;
    }

    public function address(): ?Address
    {
        return $this->address;
    }

    public function supportEmail(): ?string
    {
        return $this->supportEmail;
    }

    public function supportPhone(): ?string
    {
        return $this->supportPhone;
    }

    public function ratingAverage(): float
    {
        return $this->ratingAverage;
    }

    public function ratingCount(): int
    {
        return $this->ratingCount;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
