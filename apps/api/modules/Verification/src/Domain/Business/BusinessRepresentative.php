<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Business;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Exception\VerificationInvalidState;

/**
 * A person authorised to act for a business, and the identity case proving they
 * are who they say.
 *
 * KYB is not complete when a registration number checks out — a company record
 * says nothing about who is operating the account. The representative carries
 * their own identity verification, which is why `identityCaseId` points at a
 * normal {@see \EruoFood\Verification\Domain\VerificationCase\VerificationCase}
 * rather than duplicating identity fields here.
 *
 * `ownershipPercentage` is populated only where law requires beneficial-owner
 * disclosure; it stays null elsewhere rather than collecting data we have no
 * basis to hold.
 */
final class BusinessRepresentative
{
    private function __construct(
        private readonly string $id,
        private readonly string $businessProfileId,
        private readonly string $userId,
        private string $fullName,
        private string $role,
        private bool $isPrimary,
        private ?string $identityCaseId,
        private ?float $ownershipPercentage,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function nominate(
        string $id,
        string $businessProfileId,
        string $userId,
        string $fullName,
        string $role,
        bool $isPrimary,
        ?float $ownershipPercentage,
        DateTimeImmutable $now,
    ): self {
        if (trim($fullName) === '') {
            throw new VerificationInvalidState('A representative name is required.');
        }
        if ($ownershipPercentage !== null && ($ownershipPercentage < 0.0 || $ownershipPercentage > 100.0)) {
            throw new VerificationInvalidState('Ownership percentage must be between 0 and 100.');
        }

        return new self($id, $businessProfileId, $userId, trim($fullName), $role, $isPrimary, null, $ownershipPercentage, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $businessProfileId,
        string $userId,
        string $fullName,
        string $role,
        bool $isPrimary,
        ?string $identityCaseId,
        ?float $ownershipPercentage,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $businessProfileId, $userId, $fullName, $role, $isPrimary, $identityCaseId, $ownershipPercentage, $createdAt, $updatedAt);
    }

    public function attachIdentityCase(string $caseId, DateTimeImmutable $now): void
    {
        $this->identityCaseId = $caseId;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function businessProfileId(): string
    {
        return $this->businessProfileId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function identityCaseId(): ?string
    {
        return $this->identityCaseId;
    }

    public function ownershipPercentage(): ?float
    {
        return $this->ownershipPercentage;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
