<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Address;

/** Persistence port for {@see CustomerAddress}. */
interface CustomerAddressRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?CustomerAddress;

    /** @return list<CustomerAddress> */
    public function forUser(string $userId, bool $activeOnly = true): array;

    public function defaultFor(string $userId): ?CustomerAddress;

    public function countActiveFor(string $userId): int;

    /** Clear the default flag across a user's addresses, so exactly one can hold it. */
    public function clearDefaultFor(string $userId, ?string $exceptId = null): void;

    public function save(CustomerAddress $address): void;
}
