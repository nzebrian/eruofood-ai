<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Business;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Exception\VerificationInvalidState;

/**
 * The registered identity of a merchant, restaurant or grocery alike.
 *
 * Restaurants live in Marketplace and groceries in Commerce, and M24 keeps those
 * catalogues separate. What they share is the *question* KYB answers — does this
 * company exist, under this name, at this address, run by this person — so the
 * answer lives once here and is referenced by kind and id rather than being
 * implemented twice.
 *
 * `registrationNumber` is held encrypted by the persistence layer; the domain
 * handles it as an opaque string and never logs it.
 */
final class BusinessProfile
{
    private function __construct(
        private readonly string $id,
        private readonly string $businessKind,
        private readonly string $businessId,
        private readonly string $countryCode,
        private string $registeredName,
        private string $tradingName,
        private string $businessType,
        private string $registrationNumber,
        private string $registrationAuthority,
        /** @var array<string, mixed> */
        private array $address,
        private ?float $latitude,
        private ?float $longitude,
        private ?string $identityCaseId,
        private ?string $payoutAccountCaseId,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $address */
    public static function register(
        string $id,
        string $businessKind,
        string $businessId,
        string $countryCode,
        string $registeredName,
        string $tradingName,
        string $businessType,
        string $registrationNumber,
        string $registrationAuthority,
        array $address,
        ?float $latitude,
        ?float $longitude,
        DateTimeImmutable $now,
    ): self {
        if (! in_array($businessKind, ['restaurant', 'grocery'], true)) {
            throw new VerificationInvalidState(sprintf('Unknown business kind "%s".', $businessKind));
        }
        if (trim($registeredName) === '') {
            throw new VerificationInvalidState('A registered business name is required.');
        }
        if (trim($registrationNumber) === '') {
            throw new VerificationInvalidState('A business registration number is required.');
        }

        return new self(
            $id,
            $businessKind,
            $businessId,
            strtoupper($countryCode),
            trim($registeredName),
            trim($tradingName),
            $businessType,
            trim($registrationNumber),
            strtoupper($registrationAuthority),
            $address,
            $latitude,
            $longitude,
            null,
            null,
            $now,
            $now,
        );
    }

    /** @param array<string, mixed> $address */
    public static function reconstitute(
        string $id,
        string $businessKind,
        string $businessId,
        string $countryCode,
        string $registeredName,
        string $tradingName,
        string $businessType,
        string $registrationNumber,
        string $registrationAuthority,
        array $address,
        ?float $latitude,
        ?float $longitude,
        ?string $identityCaseId,
        ?string $payoutAccountCaseId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $businessKind,
            $businessId,
            $countryCode,
            $registeredName,
            $tradingName,
            $businessType,
            $registrationNumber,
            $registrationAuthority,
            $address,
            $latitude,
            $longitude,
            $identityCaseId,
            $payoutAccountCaseId,
            $createdAt,
            $updatedAt,
        );
    }

    public function attachVerificationCase(string $caseId, DateTimeImmutable $now): void
    {
        $this->identityCaseId = $caseId;
        $this->updatedAt = $now;
    }

    /**
     * Hook for M27: the payout account gets its own verification case so bank
     * details can be checked independently of the business registration.
     */
    public function attachPayoutAccountCase(string $caseId, DateTimeImmutable $now): void
    {
        $this->payoutAccountCaseId = $caseId;
        $this->updatedAt = $now;
    }

    /** @param array<string, mixed> $address */
    public function updateProfile(
        string $registeredName,
        string $tradingName,
        string $businessType,
        array $address,
        ?float $latitude,
        ?float $longitude,
        DateTimeImmutable $now,
    ): void {
        $this->registeredName = trim($registeredName);
        $this->tradingName = trim($tradingName);
        $this->businessType = $businessType;
        $this->address = $address;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function businessKind(): string
    {
        return $this->businessKind;
    }

    public function businessId(): string
    {
        return $this->businessId;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function registeredName(): string
    {
        return $this->registeredName;
    }

    public function tradingName(): string
    {
        return $this->tradingName;
    }

    public function businessType(): string
    {
        return $this->businessType;
    }

    public function registrationNumber(): string
    {
        return $this->registrationNumber;
    }

    public function registrationAuthority(): string
    {
        return $this->registrationAuthority;
    }

    /** @return array<string, mixed> */
    public function address(): array
    {
        return $this->address;
    }

    public function latitude(): ?float
    {
        return $this->latitude;
    }

    public function longitude(): ?float
    {
        return $this->longitude;
    }

    public function identityCaseId(): ?string
    {
        return $this->identityCaseId;
    }

    public function payoutAccountCaseId(): ?string
    {
        return $this->payoutAccountCaseId;
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
