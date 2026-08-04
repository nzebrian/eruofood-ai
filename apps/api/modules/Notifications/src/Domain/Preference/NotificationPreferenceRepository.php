<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Preference;

/** Persistence port for {@see NotificationPreference}. */
interface NotificationPreferenceRepository
{
    public function forUser(string $userId): ?NotificationPreference;

    public function save(NotificationPreference $preference): void;
}
