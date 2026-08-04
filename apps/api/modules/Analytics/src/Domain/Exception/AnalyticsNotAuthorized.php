<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user requests a dashboard/report they may not view. */
final class AnalyticsNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'ANALYTICS_NOT_AUTHORIZED';
    }
}
