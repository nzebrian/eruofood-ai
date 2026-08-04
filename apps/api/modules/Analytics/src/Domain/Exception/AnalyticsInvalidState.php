<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid analytics operation (bad range, unknown metric). */
final class AnalyticsInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'ANALYTICS_INVALID_STATE';
    }
}
