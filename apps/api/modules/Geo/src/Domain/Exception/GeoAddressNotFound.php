<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The provider was reached and found nothing.
 *
 * Distinct from {@see GeoProviderUnavailable} because the two demand different
 * responses: an unfound address is the user's to correct, an unreachable
 * provider is ours. Answering both the same way would tell somebody their real
 * address is wrong during an outage.
 */
final class GeoAddressNotFound extends DomainException
{
    public static function forQuery(): self
    {
        // The query itself is not echoed: it is somebody's home address, and
        // error messages travel further than the request that produced them.
        return new self('No location could be found for that address.');
    }

    public static function forCoordinates(): self
    {
        return new self('No address could be found at those coordinates.');
    }

    public function errorCode(): string
    {
        return 'GEO_ADDRESS_NOT_FOUND';
    }
}
