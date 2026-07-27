<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/** The lifecycle of a return/refund request. */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Requested => in_array($next, [self::Approved, self::Rejected], true),
            self::Approved => $next === self::Refunded,
            default => false,
        };
    }
}
