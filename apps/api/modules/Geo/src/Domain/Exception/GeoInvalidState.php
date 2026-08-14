<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** A geographic operation attempted against a record that cannot accept it. */
final class GeoInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'GEO_INVALID_STATE';
    }
}
