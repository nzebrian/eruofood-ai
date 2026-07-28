<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Operations;

/** The decision state of a vendor/restaurant approval request. */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
