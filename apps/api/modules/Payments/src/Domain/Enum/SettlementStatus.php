<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The lifecycle of a vendor/driver settlement run. */
enum SettlementStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::Completed, self::Failed], true),
            default => false,
        };
    }
}
