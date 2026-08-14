<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** A geographic record that does not exist. */
final class GeoNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('No %s found for "%s".', $resource, $id));
    }

    public function errorCode(): string
    {
        return 'GEO_RESOURCE_NOT_FOUND';
    }
}
