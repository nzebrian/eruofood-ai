<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Order;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see ReturnRequest} aggregate. */
interface ReturnRequestRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ReturnRequest;

    public function existsForOrder(string $orderId): bool;

    /** @return Paginated<ReturnRequest> */
    public function forCustomer(string $customerUserId, int $page, int $perPage): Paginated;

    /** @return Paginated<ReturnRequest> */
    public function all(int $page, int $perPage): Paginated;

    public function save(ReturnRequest $request): void;
}
