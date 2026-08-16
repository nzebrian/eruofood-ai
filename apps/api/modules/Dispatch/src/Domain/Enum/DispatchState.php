<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/** Where a dispatch request stands in its search for a rider. */
enum DispatchState: string implements ServerAuthoritative
{
    /** Waiting to be picked up by a dispatch worker. */
    case Pending = 'pending';

    /** A worker holds it and is building a pool or awaiting an offer response. */
    case Dispatching = 'dispatching';

    case Assigned = 'assigned';

    /** Exhausted its attempts or its time budget. Operations owns it now. */
    case Failed = 'failed';

    case Cancelled = 'cancelled';

    /**
     * Where the search for a rider has got to.
     *
     * `Dispatching` is `Processing`: offers are live on riders' screens, and a
     * second search opened because somebody retried is the duplicate-assignment
     * failure M26 exists to prevent.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Pending => ServerPhase::Pending,
            self::Dispatching => ServerPhase::Processing,
            self::Assigned => ServerPhase::Confirmed,
            self::Failed => ServerPhase::Failed,
            self::Cancelled => ServerPhase::Cancelled,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Assigned || $this === self::Failed || $this === self::Cancelled;
    }

    /** Whether a worker may claim this request. */
    public function isClaimable(): bool
    {
        return $this === self::Pending;
    }
}
