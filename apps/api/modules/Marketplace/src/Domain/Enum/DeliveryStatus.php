<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/** The lifecycle of a delivery job. */
enum DeliveryStatus: string
{
    case Unassigned = 'unassigned';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case EnRoute = 'en_route';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
