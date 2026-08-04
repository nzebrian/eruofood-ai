<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Application\Input\VendorInput;
use EruoFood\Marketplace\Domain\Exception\MarketplaceConflict;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Exception\NotVendorOwner;
use EruoFood\Marketplace\Domain\ValueObject\Branch;
use EruoFood\Marketplace\Domain\ValueObject\BusinessHours;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * Vendor onboarding, storefront management and the verification lifecycle.
 * Owner-or-admin authorisation is enforced on every mutation.
 */
final readonly class VendorService
{
    public function __construct(
        private VendorRepository $vendors,
        private Clock $clock,
        private EventBus $events,
        private string $currency,
        private bool $requireVerification,
    ) {
    }

    public function register(string $ownerUserId, VendorInput $input): Vendor
    {
        $slug = $this->uniqueSlug($input->name);

        $vendor = Vendor::register(
            id: $this->vendors->nextIdentity(),
            ownerUserId: $ownerUserId,
            name: $input->name,
            slug: $slug,
            type: $input->type,
            category: $input->category,
            contact: $input->contact,
            address: $input->address,
            now: $this->clock->now(),
            autoVerify: ! $this->requireVerification,
        );
        $this->vendors->save($vendor);

        return $vendor;
    }

    public function get(string $id): Vendor
    {
        return $this->vendors->findById($id) ?? throw MarketplaceNotFound::of('vendor', $id);
    }

    public function getBySlug(string $slug): Vendor
    {
        return $this->vendors->findBySlug($slug) ?? throw MarketplaceNotFound::of('vendor', $slug);
    }

    /**
     * @return Paginated<Vendor>
     */
    public function search(VendorSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        return $this->vendors->search($criteria, max(1, $page), min(60, max(1, $perPage)));
    }

    /** @return list<Vendor> */
    public function mine(string $ownerUserId): array
    {
        return $this->vendors->forOwner($ownerUserId);
    }

    public function updateProfile(string $userId, bool $isAdmin, string $id, VendorInput $input): Vendor
    {
        $vendor = $this->manageable($userId, $isAdmin, $id);
        $vendor->updateProfile($input->name, $input->category, $input->description, $input->contact, $input->address);
        $this->vendors->save($vendor);

        return $vendor;
    }

    /** @param array<int|string, array{open: string, close: string}> $days */
    public function setBusinessHours(string $userId, bool $isAdmin, string $id, array $days): Vendor
    {
        $vendor = $this->manageable($userId, $isAdmin, $id);
        $vendor->setBusinessHours(BusinessHours::fromArray($days));
        $this->vendors->save($vendor);

        return $vendor;
    }

    /** @param list<array<string, mixed>> $zones */
    public function setDeliveryZones(string $userId, bool $isAdmin, string $id, array $zones): Vendor
    {
        $vendor = $this->manageable($userId, $isAdmin, $id);
        $vendor->setDeliveryZones(array_map(
            fn (array $z): DeliveryZone => DeliveryZone::fromArray($z, $this->currency),
            $zones,
        ));
        $this->vendors->save($vendor);

        return $vendor;
    }

    /** @param list<array<string, mixed>> $branches */
    public function setBranches(string $userId, bool $isAdmin, string $id, array $branches): Vendor
    {
        $vendor = $this->manageable($userId, $isAdmin, $id);
        $vendor->setBranches(array_map(
            function (array $b): Branch {
                $b['id'] ??= (string) \Illuminate\Support\Str::orderedUuid();

                return Branch::fromArray($b);
            },
            $branches,
        ));
        $this->vendors->save($vendor);

        return $vendor;
    }

    /** @param list<string> $images */
    public function setImages(string $userId, bool $isAdmin, string $id, array $images): Vendor
    {
        $vendor = $this->manageable($userId, $isAdmin, $id);
        $vendor->setImages($images);
        $this->vendors->save($vendor);

        return $vendor;
    }

    // ---- Admin ----------------------------------------------------------

    public function verify(string $id): Vendor
    {
        $vendor = $this->get($id);
        $vendor->verify();
        $this->vendors->save($vendor);
        $this->events->publish(...$vendor->releaseEvents());

        return $vendor;
    }

    public function reject(string $id): Vendor
    {
        return $this->applyAdmin($id, static fn (Vendor $v) => $v->reject());
    }

    public function suspend(string $id): Vendor
    {
        return $this->applyAdmin($id, static fn (Vendor $v) => $v->suspend());
    }

    public function setFeatured(string $id, bool $featured): Vendor
    {
        return $this->applyAdmin($id, static fn (Vendor $v) => $v->setFeatured($featured));
    }

    /** Load a vendor the actor is allowed to manage (owner or admin). */
    public function manageable(string $userId, bool $isAdmin, string $id): Vendor
    {
        $vendor = $this->get($id);
        if (! $isAdmin && ! $vendor->isOwnedBy($userId)) {
            throw new NotVendorOwner();
        }

        return $vendor;
    }

    private function applyAdmin(string $id, callable $mutator): Vendor
    {
        $vendor = $this->get($id);
        $mutator($vendor);
        $this->vendors->save($vendor);

        return $vendor;
    }

    private function uniqueSlug(string $name): Slug
    {
        $base = Slug::fromTitle($name);
        if (! $this->vendors->slugExists((string) $base)) {
            return $base;
        }
        $candidate = new Slug($base.'-'.substr(bin2hex(random_bytes(3)), 0, 5));
        if ($this->vendors->slugExists((string) $candidate)) {
            throw new MarketplaceConflict('Could not allocate a unique vendor slug; try a different name.');
        }

        return $candidate;
    }
}
