<?php

declare(strict_types=1);

namespace EruoFood\Platform\Interface\Http\Controller;

use EruoFood\Platform\Application\Query\GetSystemStatus;
use EruoFood\Platform\Application\Query\GetSystemStatusHandler;
use EruoFood\Platform\Interface\Http\Resource\SystemStatusResource;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller for the health/status endpoint.
 *
 * The controller does no work beyond dispatching a query and transforming the
 * result. All logic lives in the application/domain layers (Single
 * Responsibility Principle; controllers stay thin — MASTER_PLAN.md §2.2).
 */
final readonly class HealthController
{
    public function __construct(private GetSystemStatusHandler $handler)
    {
    }

    public function __invoke(): JsonResponse
    {
        $status = ($this->handler)(new GetSystemStatus());

        return SystemStatusResource::make($status)->response();
    }
}
