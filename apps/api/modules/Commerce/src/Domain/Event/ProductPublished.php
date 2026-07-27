<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a product is approved and goes live in the catalogue. */
final readonly class ProductPublished implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $productId,
        public string $storeId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'commerce.product_published';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
