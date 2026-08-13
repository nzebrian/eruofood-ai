<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Port;

use EruoFood\Notifications\Application\DTO\Recipient;

/**
 * Turns a user id into a deliverable {@see Recipient}.
 *
 * A port because the identity store belongs to another context. Returning null
 * for an unknown or address-less user is a normal outcome, not an error: an
 * account deleted between the event and the send should make delivery a no-op,
 * never an exception that rolls something back.
 */
interface RecipientResolver
{
    public function resolve(string $userId): ?Recipient;
}
