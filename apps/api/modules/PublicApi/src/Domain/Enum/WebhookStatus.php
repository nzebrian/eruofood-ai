<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Enum;

/** Whether a webhook endpoint receives deliveries. */
enum WebhookStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
