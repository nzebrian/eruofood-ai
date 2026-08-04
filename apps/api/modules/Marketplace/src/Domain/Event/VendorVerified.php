<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Emitted when an admin verifies a vendor (they may now trade). */
final readonly class VendorVerified implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public string $vendorId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'marketplace.vendor_verified';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
