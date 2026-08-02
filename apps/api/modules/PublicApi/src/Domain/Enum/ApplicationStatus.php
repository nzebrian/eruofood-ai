<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Enum;

/** Lifecycle of a developer application (API client). */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
