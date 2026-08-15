<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/** Where a dispatch request stands in its search for a rider. */
enum DispatchState: string
{
    /** Waiting to be picked up by a dispatch worker. */
    case Pending = 'pending';

    /** A worker holds it and is building a pool or awaiting an offer response. */
    case Dispatching = 'dispatching';

    case Assigned = 'assigned';

    /** Exhausted its attempts or its time budget. Operations owns it now. */
    case Failed = 'failed';

    case Cancelled = 'cancelled';

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
