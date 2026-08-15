<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/**
 * Whether a human has checked this vehicle's paperwork.
 *
 * Deliberately separate from M24's *identity* verification, and from
 * {@see VehicleStatus}. Three different questions:
 *
 * - M24: is this person who they say they are?
 * - This: are this vehicle's documents genuine and current?
 * - Status: may it be used right now?
 *
 * A fully KYC-verified rider can own an uninsured vehicle. Conflating the two
 * would let identity verification silently vouch for a vehicle nobody
 * inspected.
 */
enum VehicleVerificationState: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** Documents were accepted once and have since lapsed. */
    case Expired = 'expired';

    public function permitsDispatch(): bool
    {
        return $this === self::Verified;
    }

    /** Whether a rider may resubmit — a rejection is not always final. */
    public function isResubmittable(): bool
    {
        return $this === self::Rejected || $this === self::Expired;
    }
}
