<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\User;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
