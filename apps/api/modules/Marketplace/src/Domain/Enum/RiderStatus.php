<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/** A rider's availability for delivery assignment. */
enum RiderStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Offline = 'offline';
}
