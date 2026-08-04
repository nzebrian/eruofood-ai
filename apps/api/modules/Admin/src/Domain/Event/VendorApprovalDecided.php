<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when an administrator approves or rejects a vendor/restaurant
 * onboarding or compliance request. The owning context (Marketplace/Commerce)
 * listens and flips the vendor's own status; Admin never writes their tables.
 */
final readonly class VendorApprovalDecided implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $vendorId,
        public string $subjectType,
        public bool $approved,
        public string $actorId,
        public ?string $note,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'admin.vendor_approval_decided';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
