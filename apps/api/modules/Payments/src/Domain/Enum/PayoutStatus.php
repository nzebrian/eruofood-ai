<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/** The lifecycle of a payout to a bank destination. */
enum PayoutStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    /**
     * Where a payout has got to.
     *
     * `Paid` is the confirmed phase; `Processing` is in flight at the provider
     * and, like every money-moving `Processing`, is not safely retryable.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Processing => ServerPhase::Processing,
            self::Paid => ServerPhase::Confirmed,
            self::Failed => ServerPhase::Failed,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::Paid, self::Failed], true),
            default => false,
        };
    }
}
