<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\DTO;

use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/** A request to measure a journey. */
final readonly class RouteQuery
{
    public function __construct(
        public Coordinates $origin,
        public Coordinates $destination,
        public TravelMode $travelMode,
        /**
         * Traffic-aware routing costs more per call and expires far sooner.
         * Worth it for a live ETA, wasteful for a delivery-radius check.
         */
        public bool $trafficAware = false,
        public ?string $countryCode = null,
    ) {
    }
}
