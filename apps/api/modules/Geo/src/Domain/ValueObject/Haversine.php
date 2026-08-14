<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\ValueObject;

/**
 * Great-circle distance — the platform's one and only implementation.
 *
 * There were two before M25, disagreeing on the Earth's radius (6371.0 in
 * Marketplace, 6371.0088 in Search). The difference is about 1.4 metres in
 * 10 km, which is meaningless for proximity and yet meant the same journey had
 * two answers. Both now delegate here.
 *
 * ## What this is for, and what it is emphatically not for
 *
 * Haversine measures a straight line over a sphere. Roads are neither straight
 * nor on a sphere. In Lagos the routed distance commonly runs 1.3–1.6× the
 * straight line, and across a bridge or through a one-way system considerably
 * worse.
 *
 * So this is legitimate for:
 *
 * - bounding-box and radius pre-filtering ("which vendors are plausibly near?")
 * - candidate selection before an expensive routing call
 * - sorting search results by rough proximity
 * - sanity-checking a routed result that looks absurd
 *
 * and it is **never** the distance a customer is billed for. That is the
 * routing provider's job, and {@see \EruoFood\Geo\Domain\Route\Route} carries a
 * `source` field precisely so pricing can refuse anything that did not come
 * from one. A straight-line billing distance is not merely imprecise: it is
 * wrong in one direction, systematically, on every single order.
 */
final class Haversine
{
    /**
     * IUGG mean Earth radius in metres.
     *
     * The value Search used. Adopted over Marketplace's rounder 6371.0 because
     * it is the defensible one; the difference is immaterial, but a single
     * arbitrary constant beats two.
     */
    public const EARTH_RADIUS_METRES = 6_371_008.8;

    private function __construct()
    {
    }

    /** Great-circle distance in metres. */
    public static function metres(Coordinates $from, Coordinates $to): float
    {
        $latFrom = deg2rad($from->latitude);
        $latTo = deg2rad($to->latitude);
        $deltaLat = $latTo - $latFrom;
        $deltaLon = deg2rad($to->longitude - $from->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($deltaLon / 2) ** 2;

        // atan2 rather than asin: numerically stable for antipodal points,
        // where asin loses precision badly.
        return self::EARTH_RADIUS_METRES * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** Great-circle distance in kilometres. */
    public static function kilometres(Coordinates $from, Coordinates $to): float
    {
        return self::metres($from, $to) / 1000.0;
    }

    /**
     * A latitude/longitude box that certainly contains everything within
     * $radiusMetres, and some things outside it.
     *
     * The cheap half of proximity search: SQL narrows to the box using an
     * index, then the caller measures the survivors precisely. Longitude
     * degrees shrink towards the poles, hence the cosine term; it is clamped so
     * a query near a pole widens the box rather than dividing by zero.
     *
     * @return array{minLat: float, maxLat: float, minLon: float, maxLon: float}
     */
    public static function boundingBox(Coordinates $centre, float $radiusMetres): array
    {
        $latDelta = rad2deg($radiusMetres / self::EARTH_RADIUS_METRES);

        $cos = cos(deg2rad($centre->latitude));
        $lonDelta = rad2deg($radiusMetres / (self::EARTH_RADIUS_METRES * max(0.01, abs($cos))));

        return [
            'minLat' => max(-90.0, $centre->latitude - $latDelta),
            'maxLat' => min(90.0, $centre->latitude + $latDelta),
            'minLon' => max(-180.0, $centre->longitude - $lonDelta),
            'maxLon' => min(180.0, $centre->longitude + $lonDelta),
        ];
    }

    public static function isWithin(Coordinates $centre, Coordinates $point, float $radiusMetres): bool
    {
        return self::metres($centre, $point) <= $radiusMetres;
    }
}
