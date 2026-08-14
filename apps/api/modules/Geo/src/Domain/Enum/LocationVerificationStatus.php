<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/**
 * How much the platform trusts a location.
 *
 * Deliberately separate from M24's identity verification. A business can be
 * fully KYB-verified and still have an address that geocodes to the wrong
 * street — the two answer different questions, and conflating them would let a
 * verified merchant inherit a location nobody checked.
 */
enum LocationVerificationStatus: string
{
    case Unverified = 'unverified';
    case Geocoded = 'geocoded';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';

    /** Whether this location may be used for dispatch and public listing. */
    public function isUsable(): bool
    {
        return in_array($this, [self::Geocoded, self::Confirmed], true);
    }
}
