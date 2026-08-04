<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Order;

use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for orders (Repository Pattern). */
interface OrderRepository
{
    public function nextIdentity(): string;

    /** A short, human-friendly order reference (e.g. "EF-7F3A9C"). */
    public function nextReference(): string;

    public function findById(string $id): ?Order;

    /**
     * A customer's order history, newest first.
     *
     * @return Paginated<Order>
     */
    public function forCustomer(string $userId, int $page, int $perPage): Paginated;

    /**
     * A vendor's orders, optionally filtered by status, newest first.
     *
     * @return Paginated<Order>
     */
    public function forVendor(string $vendorId, ?OrderStatus $status, int $page, int $perPage): Paginated;

    /**
     * Aggregate sales figures for a vendor.
     *
     * @return array{total: int, delivered: int, pending: int, revenue_minor: int}
     */
    public function salesSummary(string $vendorId): array;

    public function save(Order $order): void;
}
