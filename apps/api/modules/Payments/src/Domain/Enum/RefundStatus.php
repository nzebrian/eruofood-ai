<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/** The lifecycle of a refund. */
enum RefundStatus: string implements ServerAuthoritative
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * A refund's own phase, independent of the payment it refunds.
     *
     * `Pending` maps to `Pending` rather than `Processing`: a refund is
     * recorded before the provider acts on it, so nothing irreversible has
     * happened. That makes it honest to show as "requested" rather than "on its
     * way back to you".
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Completed => ServerPhase::Confirmed,
            self::Failed => ServerPhase::Failed,
        };
    }
}
