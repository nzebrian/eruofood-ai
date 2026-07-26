<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Delivery;

use EruoFood\Marketplace\Application\Port\RouteOptimizer;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;

/**
 * Nearest-neighbour route heuristic: from the current point, repeatedly visit the
 * closest unvisited stop. Cheap and dependency-free; a production deployment can
 * swap in an external routing engine behind the same {@see RouteOptimizer} port.
 */
final readonly class NearestFirstRouteOptimizer implements RouteOptimizer
{
    public function order(GeoLocation $start, array $stops): array
    {
        $remaining = array_keys($stops);
        $current = $start;
        $ordered = [];

        while ($remaining !== []) {
            $bestIndex = null;
            $bestDistance = INF;
            foreach ($remaining as $i) {
                $distance = $current->distanceKmTo($stops[$i]);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $i;
                }
            }
            /** @var int $bestIndex */
            $ordered[] = $bestIndex;
            $current = $stops[$bestIndex];
            $remaining = array_values(array_filter($remaining, static fn (int $i): bool => $i !== $bestIndex));
        }

        return $ordered;
    }
}
