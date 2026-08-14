<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\DTO;

use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * A request to turn text into a place.
 *
 * `countryCode` and `bias` narrow the search rather than restrict it: "Allen
 * Avenue" exists in several Nigerian cities and in other countries entirely,
 * and a request made from Lagos almost certainly means the Lagos one.
 */
final readonly class GeocodeQuery
{
    public function __construct(
        public string $address,
        public ?string $countryCode = null,
        public ?Coordinates $bias = null,
        public ?string $language = null,
    ) {
    }

    /** A normalised form for cache keys — case and whitespace are not meaningful. */
    public function normalised(): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($this->address)) ?? $this->address;

        return mb_strtolower($collapsed).'|'.mb_strtolower((string) $this->countryCode);
    }
}
