<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Enum;

/** The lifecycle of a reward redemption. */
enum RedemptionStatus: string
{
    case Issued = 'issued';       // code issued, awaiting use
    case Fulfilled = 'fulfilled'; // the benefit was applied by the consuming context
    case Cancelled = 'cancelled'; // reversed; points refunded

    public function isOpen(): bool
    {
        return $this === self::Issued;
    }
}
