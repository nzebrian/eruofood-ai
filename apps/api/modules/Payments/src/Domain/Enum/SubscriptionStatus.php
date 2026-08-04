<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The lifecycle of a recurring subscription. */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
}
