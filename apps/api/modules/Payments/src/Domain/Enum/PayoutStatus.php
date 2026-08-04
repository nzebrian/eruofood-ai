<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The lifecycle of a payout to a bank destination. */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::Paid, self::Failed], true),
            default => false,
        };
    }
}
