<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Resource;

use EruoFood\Identity\Application\DTO\SessionView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SessionView $resource
 */
final class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->resource->sessionId,
            'ip_address' => $this->resource->ipAddress,
            'user_agent' => $this->resource->userAgent,
            'created_at' => $this->resource->createdAt->format(DATE_ATOM),
            'last_used_at' => $this->resource->lastUsedAt?->format(DATE_ATOM),
        ];
    }
}
