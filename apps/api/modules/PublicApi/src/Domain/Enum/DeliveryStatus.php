<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Enum;

/** Lifecycle of a single webhook delivery. */
enum DeliveryStatus: string
{
    case Pending = 'pending';     // queued, awaiting an attempt
    case Delivered = 'delivered'; // 2xx received
    case Retrying = 'retrying';   // failed, more attempts remain
    case Failed = 'failed';       // attempts exhausted

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
