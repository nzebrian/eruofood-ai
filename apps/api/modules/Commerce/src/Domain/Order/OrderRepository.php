<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Order;

use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Order} aggregate. */
interface OrderRepository
{
    public function nextIdentity(): string;

    public function nextReference(): string;

    public function findById(string $id): ?Order;

    /** @return Paginated<Order> */
    public function forCustomer(string $customerUserId, int $page, int $perPage): Paginated;

    /**
     * Orders containing at least one line from the given store (seller view).
     *
     * @return Paginated<Order>
     */
    public function forStore(string $storeId, ?OrderStatus $status, int $page, int $perPage): Paginated;

    /**
     * All orders (admin monitoring), newest first.
     *
     * @return Paginated<Order>
     */
    public function all(?OrderStatus $status, int $page, int $perPage): Paginated;

    /** @return array{orders: int, revenue_minor: int} sales totals for a store */
    public function salesSummaryForStore(string $storeId): array;

    public function save(Order $order): void;
}
