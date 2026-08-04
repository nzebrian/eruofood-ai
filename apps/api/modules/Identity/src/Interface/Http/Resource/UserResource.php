<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Resource;

use EruoFood\Identity\Application\DTO\UserProfileView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property UserProfileView $resource
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'email_verified' => $this->resource->emailVerified,
            'avatar_url' => $this->resource->avatarUrl,
            'roles' => $this->resource->roles,
            'permissions' => $this->resource->permissions,
            'preferences' => $this->resource->preferences,
            'two_factor_enabled' => $this->resource->twoFactorEnabled,
            'status' => $this->resource->status,
        ];
    }
}
