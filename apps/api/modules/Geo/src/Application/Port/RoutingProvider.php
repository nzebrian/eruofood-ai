<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Domain\Route\Route;

/**
 * Measures a journey along real roads.
 *
 * The reason M25 exists. Straight-line distance under-states road distance by
 * 30–60% in Lagos, in one direction, on every order — an error invisible in any
 * single case and systematic across all of them.
 *
 * A returned {@see Route} must carry `RouteSource::Provider`; the caller relies
 * on that field to decide whether the distance may be billed.
 */
interface RoutingProvider
{
    public function name(): string;

    public function route(RouteQuery $query): Route;
}
