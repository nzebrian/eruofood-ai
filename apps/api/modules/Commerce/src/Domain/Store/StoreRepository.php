<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Store;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Store} aggregate. */
interface StoreRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Store;

    public function findBySlug(string $slug): ?Store;

    public function slugExists(string $slug): bool;

    /** @return list<Store> */
    public function forOwner(string $ownerUserId): array;

    /**
     * List verified stores.
     *
     * @return Paginated<Store>
     */
    public function listVerified(?string $term, int $page, int $perPage): Paginated;

    public function save(Store $store): void;
}
