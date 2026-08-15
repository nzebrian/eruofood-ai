<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/** Whether a vehicle may be used for work at all. */
enum VehicleStatus: string
{
    /** Registered, awaiting an operator's verification decision. */
    case PendingVerification = 'pending_verification';

    case Active = 'active';

    /** Withdrawn by operations — an incident, a failed inspection, a dispute. */
    case Suspended = 'suspended';

    /** The rider no longer has it. Kept for the record, never dispatched on. */
    case Retired = 'retired';

    public function isDispatchable(): bool
    {
        return $this === self::Active;
    }
}
