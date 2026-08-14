<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/** How a delivery zone describes its area. */
enum ZoneType: string
{
    /** A circle around a point — how every zone works today. */
    case Radius = 'radius';

    /** An arbitrary boundary, for areas a circle describes badly (a lagoon, an estate). */
    case Polygon = 'polygon';

    /** A named administrative area, e.g. a state or LGA. */
    case Administrative = 'administrative';
}
