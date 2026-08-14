<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/** Where a set of coordinates came from. Recorded because trust differs by origin. */
enum LocationSource: string
{
    /** Geocoded from an address by a provider. */
    case Geocoded = 'geocoded';

    /** Supplied by a device's positioning hardware. */
    case Device = 'device';

    /** Placed by a person — a merchant dragging a pin, an operator correcting a record. */
    case Manual = 'manual';

    /** Carried over from a pre-M25 record whose provenance is unknown. */
    case Imported = 'imported';
}
