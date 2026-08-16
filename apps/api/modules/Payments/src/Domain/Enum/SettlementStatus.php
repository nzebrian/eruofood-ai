<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/** The lifecycle of a vendor/driver settlement run. */
enum SettlementStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Where a settlement run has got to.
     *
     * `Processing` maps to the phase that refuses retry — a settlement being
     * paid out is exactly what must not be started a second time by an operator
     * who did not see a response.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Processing => ServerPhase::Processing,
            self::Completed => ServerPhase::Confirmed,
            self::Failed => ServerPhase::Failed,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::Completed, self::Failed], true),
            default => false,
        };
    }
}
