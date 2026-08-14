<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\DTO;

use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;

/**
 * What a provider found — expressed entirely in EruoFood's own terms.
 *
 * Nothing Google-shaped survives this boundary: no `address_components`, no
 * `plus_code`, no `location_type` string. The adapter translates, and the rest
 * of the platform never learns which provider answered.
 */
final readonly class GeocodeResult
{
    public function __construct(
        public Coordinates $coordinates,
        public PostalAddress $address,
        public LocationPrecision $precision,
        public string $provider,
        public ?string $providerPlaceId = null,
    ) {
    }

    public function confidence(): float
    {
        return $this->precision->confidence();
    }
}
