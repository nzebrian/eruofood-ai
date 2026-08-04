<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a notification, template, conversation or message is missing. */
final class NotificationsNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'NOTIFICATIONS_RESOURCE_NOT_FOUND';
    }
}
