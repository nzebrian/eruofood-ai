<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Order;

/** A public, transformer-ready view of an order — decoupled from the Order aggregate. */
final readonly class OrderResource
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $status,
        public string $customerUserId,
        public int $totalMinor,
        public string $currency,
        public bool $pickup,
        public ?string $note,
        public array $lines,
        public string $placedAt,
    ) {
    }
}
