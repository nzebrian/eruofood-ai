<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a report or scheduled report is missing. */
final class AnalyticsNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'ANALYTICS_RESOURCE_NOT_FOUND';
    }
}
