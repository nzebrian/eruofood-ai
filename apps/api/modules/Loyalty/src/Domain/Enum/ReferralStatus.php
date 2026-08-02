<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Enum;

/** The lifecycle of a referral attribution. */
enum ReferralStatus: string
{
    case Pending = 'pending';     // referee attributed, awaiting the qualifying event
    case Qualified = 'qualified'; // qualifying event fired; both sides rewarded

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
