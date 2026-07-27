<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (e.g. a duplicate template key). */
final class NotificationsConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'NOTIFICATIONS_CONFLICT';
    }
}
