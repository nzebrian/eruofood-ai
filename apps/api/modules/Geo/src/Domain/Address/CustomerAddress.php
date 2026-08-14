<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Address;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\AddressLabel;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;

/**
 * A customer's saved delivery address.
 *
 * The distinction this type exists to hold: a saved address is somewhere a
 * customer deliberately chose to receive deliveries. It is **not** wherever
 * their phone happened to be when they opened the app. Device position is a
 * request parameter used to bias suggestions and pre-fill a form; it never
 * becomes an address without an explicit act. Conflating the two is how food
 * arrives at the office somebody was standing outside when they ordered.
 *
 * The geographic facts live on a {@see \EruoFood\Geo\Domain\Location\Location};
 * what lives here is the customer's relationship to it — their label, their
 * instructions, whether it is the default.
 */
final class CustomerAddress
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $locationId,
        private AddressLabel $label,
        private ?string $customName,
        private ?string $deliveryInstructions,
        private ?string $contactPhone,
        private bool $isDefault,
        private bool $isActive,
        private ?DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $id,
        string $userId,
        string $locationId,
        AddressLabel $label,
        DateTimeImmutable $now,
        ?string $customName = null,
        ?string $deliveryInstructions = null,
        ?string $contactPhone = null,
        bool $isDefault = false,
    ): self {
        if ($label->requiresCustomName() && ($customName === null || trim($customName) === '')) {
            throw new GeoInvalidState('An "other" address needs a name so the customer can tell it apart.');
        }

        return new self(
            $id,
            $userId,
            $locationId,
            $label,
            $customName,
            $deliveryInstructions,
            $contactPhone,
            $isDefault,
            true,
            null,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $locationId,
        AddressLabel $label,
        ?string $customName,
        ?string $deliveryInstructions,
        ?string $contactPhone,
        bool $isDefault,
        bool $isActive,
        ?DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $userId,
            $locationId,
            $label,
            $customName,
            $deliveryInstructions,
            $contactPhone,
            $isDefault,
            $isActive,
            $lastUsedAt,
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * Object-level ownership.
     *
     * Addresses are addressed by UUID, and holding a UUID must never be the
     * same as being entitled to what is behind it — the check every caller
     * makes on the loaded row rather than on the identifier.
     */
    public function belongsTo(string $userId): bool
    {
        return $this->userId === $userId;
    }

    public function rename(AddressLabel $label, ?string $customName, DateTimeImmutable $now): void
    {
        if ($label->requiresCustomName() && ($customName === null || trim($customName) === '')) {
            throw new GeoInvalidState('An "other" address needs a name so the customer can tell it apart.');
        }

        $this->label = $label;
        $this->customName = $customName;
        $this->updatedAt = $now;
    }

    public function updateInstructions(?string $instructions, ?string $contactPhone, DateTimeImmutable $now): void
    {
        $this->deliveryInstructions = $instructions;
        $this->contactPhone = $contactPhone;
        $this->updatedAt = $now;
    }

    public function relocate(string $locationId, DateTimeImmutable $now): void
    {
        $this->locationId = $locationId;
        $this->updatedAt = $now;
    }

    public function makeDefault(DateTimeImmutable $now): void
    {
        if (! $this->isActive) {
            throw new GeoInvalidState('A deactivated address cannot be made the default.');
        }

        $this->isDefault = true;
        $this->updatedAt = $now;
    }

    public function clearDefault(DateTimeImmutable $now): void
    {
        $this->isDefault = false;
        $this->updatedAt = $now;
    }

    /**
     * Deactivate rather than delete.
     *
     * Past orders reference this address, and an order whose destination
     * vanished is one nobody can investigate when a customer disputes it.
     */
    public function deactivate(DateTimeImmutable $now): void
    {
        $this->isActive = false;
        $this->isDefault = false;
        $this->updatedAt = $now;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
        $this->updatedAt = $now;
    }

    public function displayName(): string
    {
        return $this->label === AddressLabel::Other && $this->customName !== null
            ? $this->customName
            : ucfirst($this->label->value);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function locationId(): string
    {
        return $this->locationId;
    }

    public function label(): AddressLabel
    {
        return $this->label;
    }

    public function customName(): ?string
    {
        return $this->customName;
    }

    public function deliveryInstructions(): ?string
    {
        return $this->deliveryInstructions;
    }

    public function contactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
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
