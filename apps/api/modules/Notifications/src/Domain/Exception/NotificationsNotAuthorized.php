<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user acts on a notification/conversation they are not part of. */
final class NotificationsNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'NOTIFICATIONS_NOT_AUTHORIZED';
    }
}
