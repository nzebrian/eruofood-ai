<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Vendor;

use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;

/** Filters for browsing/searching verified vendors. */
final readonly class VendorSearchCriteria
{
    public function __construct(
        public ?string $term = null,
        public ?VendorType $type = null,
        public ?string $category = null,
        public ?GeoLocation $near = null,
        public ?float $radiusKm = null,
        public bool $featuredOnly = false,
        public string $sort = 'rating', // rating | nearest | name | recent
    ) {
    }
}
