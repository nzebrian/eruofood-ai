<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\DTO\GeocodeResult;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * Turns addresses into points and back.
 *
 * A narrow port rather than one broad `MapProvider`, so a geocoding-only
 * provider does not have to stub out routing it cannot do — and so a future
 * regional provider can be adopted for one capability without being trusted
 * with all of them.
 *
 * Implementations translate their own errors into EruoFood's:
 * {@see \EruoFood\Geo\Domain\Exception\GeoAddressNotFound} when the provider
 * answered and found nothing, {@see
 * \EruoFood\Geo\Domain\Exception\GeoProviderUnavailable} when it could not
 * answer. The distinction matters: the first is the user's to fix, the second
 * is ours, and telling somebody their real address is wrong during an outage is
 * a poor way to find out.
 */
interface GeocodingProvider
{
    /** A stable name for logs, cache keys and delivery records. */
    public function name(): string;

    public function geocode(GeocodeQuery $query): GeocodeResult;

    public function reverseGeocode(Coordinates $coordinates, ?string $language = null): GeocodeResult;
}
