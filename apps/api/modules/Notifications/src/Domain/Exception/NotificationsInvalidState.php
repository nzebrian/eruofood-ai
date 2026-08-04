<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid notification/message state transition or a disallowed action. */
final class NotificationsInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'NOTIFICATIONS_INVALID_STATE';
    }
}
