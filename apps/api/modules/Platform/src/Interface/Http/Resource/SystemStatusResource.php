<?php

declare(strict_types=1);

namespace EruoFood\Platform\Interface\Http\Resource;

use EruoFood\Platform\Domain\SystemStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a SystemStatus domain object into the platform's standard API
 * envelope (MASTER_PLAN.md §6.3). Presentation concern only.
 *
 * @property SystemStatus $resource
 */
final class SystemStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->state(),
            'service' => $this->resource->service,
            'version' => $this->resource->version,
            'environment' => $this->resource->environment,
            'timestamp' => $this->resource->checkedAt->format(DATE_ATOM),
        ];
    }
}
