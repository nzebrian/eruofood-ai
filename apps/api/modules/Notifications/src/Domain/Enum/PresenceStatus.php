<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/** A user's real-time presence. */
enum PresenceStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Offline = 'offline';
}
