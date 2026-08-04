<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Support;

/** The lifecycle state of a support ticket. */
enum TicketStatus: string
{
    case Open = 'open';           // awaiting first response
    case Pending = 'pending';     // waiting on the requester
    case Resolved = 'resolved';   // answered, pending confirmation
    case Closed = 'closed';       // done
}
