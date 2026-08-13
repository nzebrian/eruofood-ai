<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Preference;

/** Persistence port for {@see NotificationPreference}. */
interface NotificationPreferenceRepository
{
    public function forUser(string $userId): ?NotificationPreference;

    /**
     * Look a user up by their unsubscribe token.
     *
     * The only lookup that does not start from an authenticated identity, which
     * is what lets an unsubscribe link work from an email client.
     */
    public function forUnsubscribeToken(string $token): ?NotificationPreference;

    public function save(NotificationPreference $preference): void;
}
