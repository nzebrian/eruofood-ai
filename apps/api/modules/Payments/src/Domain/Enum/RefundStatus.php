<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The lifecycle of a refund. */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
