<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The caller may not see or change this location.
 *
 * The message is uniform and says nothing about whether the record exists —
 * distinguishing "not yours" from "no such address" would turn the endpoint
 * into a way of discovering other people's saved addresses.
 */
final class GeoNotAuthorized extends DomainException
{
    public function __construct(string $message = 'You are not permitted to access this location.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'GEO_NOT_AUTHORIZED';
    }
}
