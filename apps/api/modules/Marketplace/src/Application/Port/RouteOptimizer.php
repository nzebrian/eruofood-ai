<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Port;

use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;

/**
 * Orders a set of delivery stops into an efficient visiting sequence.
 *
 * Architecture-ready seam: the default adapter is a nearest-neighbour heuristic;
 * a production implementation could call an external routing engine (OSRM,
 * Google Directions) without changing any caller.
 */
interface RouteOptimizer
{
    /**
     * @param list<GeoLocation> $stops
     * @return list<int> the input indices in the order they should be visited
     */
    public function order(GeoLocation $start, array $stops): array;
}
