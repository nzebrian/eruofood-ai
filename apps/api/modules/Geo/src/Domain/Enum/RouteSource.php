<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/**
 * Where a route's numbers came from — the single most important field on a
 * route.
 *
 * Delivery pricing consults this before billing anything. A provider result is
 * authoritative; a cached one is authoritative while fresh; a haversine
 * estimate never is, at any age. Without this field a fallback is
 * indistinguishable from a real answer, which is exactly how a straight-line
 * guess ends up on a customer's bill.
 */
enum RouteSource: string
{
    case Provider = 'provider';
    case Cache = 'cache';
    case Haversine = 'haversine';

    /** Whether a distance from this source may be charged for. */
    public function isBillable(): bool
    {
        return $this !== self::Haversine;
    }
}
