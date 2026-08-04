<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/** A vendor's lifecycle/verification state. Only Verified vendors can trade. */
enum VendorStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function canTrade(): bool
    {
        return $this === self::Verified;
    }
}
