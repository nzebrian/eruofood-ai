<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\ValueObject\Batch;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A stock record for a product (optionally a specific variant SKU) held in a
 * warehouse. The aggregate root for stock management: it owns the on-hand
 * quantity, a low-stock threshold for alerting, batch/lot tracking with expiry
 * dates, and the soft link to a supplier.
 *
 * On-hand quantity is the source of truth; the batch list is a parallel,
 * finer-grained view for expiry management. Deductions consume the
 * earliest-expiring batches first (FEFO).
 */
final class InventoryItem
{
    /**
     * @param list<Batch> $batches
     */
    private function __construct(
        private readonly string $id,
        private readonly string $productId,
        private readonly ?string $variantSku,
        private ?string $warehouseId,
        private ?string $supplierId,
        private int $quantity,
        private int $lowStockThreshold,
        private array $batches,
    ) {
    }

    public static function open(
        string $id,
        string $productId,
        ?string $variantSku,
        ?string $warehouseId,
        ?string $supplierId,
        int $quantity,
        int $lowStockThreshold,
    ): self {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative.');
        }

        return new self(
            $id,
            $productId,
            $variantSku,
            $warehouseId,
            $supplierId,
            $quantity,
            max(0, $lowStockThreshold),
            [],
        );
    }

    /**
     * @param list<Batch> $batches
     */
    public static function reconstitute(
        string $id,
        string $productId,
        ?string $variantSku,
        ?string $warehouseId,
        ?string $supplierId,
        int $quantity,
        int $lowStockThreshold,
        array $batches,
    ): self {
        return new self(
            $id,
            $productId,
            $variantSku,
            $warehouseId,
            $supplierId,
            $quantity,
            $lowStockThreshold,
            array_values($batches),
        );
    }

    /** Receive stock, optionally as a tracked batch (with expiry). */
    public function receive(int $quantity, ?Batch $batch = null): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Received quantity must be positive.');
        }
        $this->quantity += $quantity;
        if ($batch !== null) {
            $this->batches[] = $batch;
        }
    }

    /** Deduct sold/removed stock, consuming earliest-expiring batches first. */
    public function deduct(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Deducted quantity must be positive.');
        }
        if ($quantity > $this->quantity) {
            throw new CommerceInvalidState('Insufficient stock for this operation.');
        }
        $this->quantity -= $quantity;
        $this->consumeBatches($quantity);
    }

    public function setLowStockThreshold(int $threshold): void
    {
        $this->lowStockThreshold = max(0, $threshold);
    }

    public function assignSupplier(?string $supplierId): void
    {
        $this->supplierId = $supplierId;
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->lowStockThreshold;
    }

    public function hasStock(int $quantity): bool
    {
        return $this->quantity >= $quantity;
    }

    public function nearestExpiry(): ?DateTimeImmutable
    {
        $nearest = null;
        foreach ($this->batches as $batch) {
            if ($batch->expiresAt === null) {
                continue;
            }
            if ($nearest === null || $batch->expiresAt < $nearest) {
                $nearest = $batch->expiresAt;
            }
        }

        return $nearest;
    }

    /** @return list<Batch> batches expiring on/before now + $days */
    public function expiringBatches(DateTimeImmutable $asOf, int $days): array
    {
        return array_values(array_filter(
            $this->batches,
            static fn (Batch $b): bool => $b->expiresWithin($asOf, $days),
        ));
    }

    private function consumeBatches(int $quantity): void
    {
        // FEFO: sort by expiry (nulls last), then draw down.
        $batches = $this->batches;
        usort($batches, static function (Batch $a, Batch $b): int {
            if ($a->expiresAt === null) {
                return $b->expiresAt === null ? 0 : 1;
            }
            if ($b->expiresAt === null) {
                return -1;
            }

            return $a->expiresAt <=> $b->expiresAt;
        });

        $remaining = $quantity;
        $result = [];
        foreach ($batches as $batch) {
            if ($remaining <= 0 || $batch->quantity === 0) {
                if ($batch->quantity > 0) {
                    $result[] = $batch;
                }
                continue;
            }
            $take = min($remaining, $batch->quantity);
            $remaining -= $take;
            $left = $batch->quantity - $take;
            if ($left > 0) {
                $result[] = new Batch($batch->batchNumber, $left, $batch->expiresAt, $batch->receivedAt);
            }
        }
        $this->batches = array_values($result);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function variantSku(): ?string
    {
        return $this->variantSku;
    }

    public function warehouseId(): ?string
    {
        return $this->warehouseId;
    }

    public function supplierId(): ?string
    {
        return $this->supplierId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function lowStockThreshold(): int
    {
        return $this->lowStockThreshold;
    }

    /** @return list<Batch> */
    public function batches(): array
    {
        return $this->batches;
    }
}
