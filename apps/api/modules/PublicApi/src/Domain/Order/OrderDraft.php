<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Order;

/**
 * The caller-supplied inputs to place an order. The order is created from the
 * customer's existing cart in the Order domain — the Public API never assembles
 * an order itself, so all pricing/inventory/business rules stay in that domain.
 */
final readonly class OrderDraft
{
    /**
     * @param array<string, mixed>|null $shippingAddress
     */
    public function __construct(
        public bool $pickup = false,
        public ?string $note = null,
        public ?string $scheduledFor = null,
        public ?array $shippingAddress = null,
    ) {
    }
}
