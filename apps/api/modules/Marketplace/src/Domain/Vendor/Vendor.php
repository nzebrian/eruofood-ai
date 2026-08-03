<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Vendor;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\VendorStatus;
use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\Event\VendorVerified;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\Branch;
use EruoFood\Marketplace\Domain\ValueObject\BusinessHours;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A food business on the platform — restaurant, market vendor, home or cloud
 * kitchen. The aggregate root for a storefront: it owns the profile, branches,
 * business hours and delivery zones, and enforces the verification lifecycle
 * (only a Verified vendor may trade).
 *
 * @phpstan-type BranchList list<Branch>
 * @phpstan-type ZoneList list<DeliveryZone>
 */
final class Vendor extends AggregateRoot
{
    /**
     * @param list<Branch> $branches
     * @param list<DeliveryZone> $deliveryZones
     * @param list<string> $images
     */
    private function __construct(
        private readonly string $id,
        private readonly string $ownerUserId,
        private string $name,
        private Slug $slug,
        private readonly VendorType $type,
        private VendorStatus $status,
        private string $category,
        private ?string $description,
        private ContactInfo $contact,
        private Address $address,
        private array $branches,
        private BusinessHours $businessHours,
        private array $deliveryZones,
        private array $images,
        private bool $featured,
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
        VendorType $type,
        string $category,
        ContactInfo $contact,
        Address $address,
        DateTimeImmutable $now,
        bool $autoVerify = false,
    ): self {
        return new self(
            $id,
            $ownerUserId,
            $name,
            $slug,
            $type,
            $autoVerify ? VendorStatus::Verified : VendorStatus::Pending,
            $category,
            null,
            $contact,
            $address,
            [],
            BusinessHours::empty(),
            [],
            [],
            false,
            0.0,
            0,
            $now,
        );
    }

    /**
     * @param list<Branch> $branches
     * @param list<DeliveryZone> $deliveryZones
     * @param list<string> $images
     */
    public static function reconstitute(
        string $id,
        string $ownerUserId,
        string $name,
        Slug $slug,
        VendorType $type,
        VendorStatus $status,
        string $category,
        ?string $description,
        ContactInfo $contact,
        Address $address,
        array $branches,
        BusinessHours $businessHours,
        array $deliveryZones,
        array $images,
        bool $featured,
        float $ratingAverage,
        int $ratingCount,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $ownerUserId,
            $name,
            $slug,
            $type,
            $status,
            $category,
            $description,
            $contact,
            $address,
            array_values($branches),
            $businessHours,
            array_values($deliveryZones),
            array_values($images),
            $featured,
            $ratingAverage,
            $ratingCount,
            $createdAt,
        );
    }

    // ---- Lifecycle ----------------------------------------------------------

    public function verify(): void
    {
        $this->status = VendorStatus::Verified;
        $this->recordThat(new VendorVerified($this->id));
    }

    public function reject(): void
    {
        $this->status = VendorStatus::Rejected;
    }

    public function suspend(): void
    {
        $this->status = VendorStatus::Suspended;
    }

    // ---- Profile ------------------------------------------------------------

    public function updateProfile(
        string $name,
        string $category,
        ?string $description,
        ContactInfo $contact,
        Address $address,
    ): void {
        $this->name = $name;
        $this->category = $category;
        $this->description = $description;
        $this->contact = $contact;
        $this->address = $address;
    }

    /** @param list<Branch> $branches */
    public function setBranches(array $branches): void
    {
        $this->branches = array_values($branches);
    }

    public function setBusinessHours(BusinessHours $hours): void
    {
        $this->businessHours = $hours;
    }

    /** @param list<DeliveryZone> $zones */
    public function setDeliveryZones(array $zones): void
    {
        $this->deliveryZones = array_values($zones);
    }

    /** @param list<string> $images */
    public function setImages(array $images): void
    {
        $this->images = array_values($images);
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function applyRatingSummary(float $average, int $count): void
    {
        $this->ratingAverage = round($average, 2);
        $this->ratingCount = $count;
    }

    // ---- Queries ------------------------------------------------------------

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerUserId === $userId;
    }

    public function canTrade(): bool
    {
        return $this->status->canTrade();
    }

    public function location(): ?GeoLocation
    {
        return $this->address->location;
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

    public function type(): VendorType
    {
        return $this->type;
    }

    public function status(): VendorStatus
    {
        return $this->status;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function contact(): ContactInfo
    {
        return $this->contact;
    }

    public function address(): Address
    {
        return $this->address;
    }

    /** @return list<Branch> */
    public function branches(): array
    {
        return $this->branches;
    }

    public function businessHours(): BusinessHours
    {
        return $this->businessHours;
    }

    /** @return list<DeliveryZone> */
    public function deliveryZones(): array
    {
        return $this->deliveryZones;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
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
