<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Application\Input\StoreInput;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Exception\NotResourceOwner;
use EruoFood\Commerce\Domain\Store\Store;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Store onboarding, profile management and the admin verification lifecycle. */
final readonly class StoreService
{
    public function __construct(
        private StoreRepository $stores,
        private bool $requireVerification,
    ) {
    }

    public function register(string $ownerUserId, string $name): Store
    {
        $slug = $this->uniqueSlug($name);
        $store = Store::register(
            $this->stores->nextIdentity(),
            $ownerUserId,
            $name,
            $slug,
            new DateTimeImmutable(),
            autoVerify: ! $this->requireVerification,
        );
        $this->stores->save($store);

        return $store;
    }

    public function update(string $storeId, string $actorUserId, bool $actorIsAdmin, StoreInput $input): Store
    {
        $store = $this->ownedStore($storeId, $actorUserId, $actorIsAdmin);
        $store->updateProfile(
            $input->name,
            $input->description,
            $input->logo,
            $input->address,
            $input->supportEmail,
            $input->supportPhone,
        );
        $this->stores->save($store);

        return $store;
    }

    public function verify(string $storeId): Store
    {
        $store = $this->getById($storeId);
        $store->verify();
        $this->stores->save($store);

        return $store;
    }

    public function suspend(string $storeId): Store
    {
        $store = $this->getById($storeId);
        $store->suspend();
        $this->stores->save($store);

        return $store;
    }

    public function getById(string $storeId): Store
    {
        return $this->stores->findById($storeId) ?? throw CommerceNotFound::of('store', $storeId);
    }

    public function getBySlug(string $slug): Store
    {
        return $this->stores->findBySlug($slug) ?? throw CommerceNotFound::of('store', $slug);
    }

    /** @return list<Store> */
    public function mine(string $ownerUserId): array
    {
        return $this->stores->forOwner($ownerUserId);
    }

    /** @return Paginated<Store> */
    public function list(?string $term, int $page, int $perPage): Paginated
    {
        return $this->stores->listVerified($term, $page, $perPage);
    }

    /** Load a store the actor owns (or is admin), or throw. */
    public function ownedStore(string $storeId, string $actorUserId, bool $actorIsAdmin): Store
    {
        $store = $this->getById($storeId);
        if (! $actorIsAdmin && ! $store->isOwnedBy($actorUserId)) {
            throw new NotResourceOwner();
        }

        return $store;
    }

    private function uniqueSlug(string $name): Slug
    {
        $base = Slug::fromTitle($name);
        if (! $this->stores->slugExists($base->value)) {
            return $base;
        }
        for ($i = 2; $i <= 50; $i++) {
            $candidate = new Slug($base->value.'-'.$i);
            if (! $this->stores->slugExists($candidate->value)) {
                return $candidate;
            }
        }
        throw new CommerceConflict('Could not generate a unique store slug.');
    }
}
