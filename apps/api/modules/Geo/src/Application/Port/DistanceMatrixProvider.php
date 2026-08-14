<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

use EruoFood\Geo\Application\DTO\RouteMatrixResult;
use EruoFood\Geo\Domain\Enum\TravelMode;

/**
 * Many-to-many distances in one call.
 *
 * Separate from {@see RoutingProvider} because the cost profile is different
 * enough to matter: N×M pairs in one request rather than N×M requests. M26
 * dispatch is the consumer; M25 provides the capability and the adapter.
 */
interface DistanceMatrixProvider
{
    public function name(): string;

    /**
     * Both arrays must be lists, and the result's cell indices are positions
     * within them.
     *
     * Stated explicitly because the providers arrive at those indices by
     * different routes — Google returns an `originIndex` of its own, the mock
     * uses the array key — and they agree only for a list. A gapped array would
     * silently pair a distance with the wrong origin, which is the sort of
     * error that reads perfectly plausibly right up until a rider is sent to it.
     *
     * A pair with no road between it is absent from the result rather than
     * present as zero.
     *
     * @param list<\EruoFood\Geo\Domain\ValueObject\Coordinates> $origins
     * @param list<\EruoFood\Geo\Domain\ValueObject\Coordinates> $destinations
     */
    public function matrix(array $origins, array $destinations, TravelMode $travelMode): RouteMatrixResult;
}
