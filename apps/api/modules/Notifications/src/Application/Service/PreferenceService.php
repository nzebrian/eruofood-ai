<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Preference\NotificationPreference;
use EruoFood\Notifications\Domain\Preference\NotificationPreferenceRepository;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;

/** A user's notification preferences (channels per category, quiet hours, language, frequency). */
final readonly class PreferenceService
{
    public function __construct(
        private NotificationPreferenceRepository $preferences,
        private string $defaultLanguage,
        private QuietHours $defaultQuietHours,
    ) {
    }

    public function get(string $userId): NotificationPreference
    {
        return $this->preferences->forUser($userId)
            ?? NotificationPreference::defaults($userId, $this->defaultLanguage, $this->defaultQuietHours);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    public function setCategoryChannels(string $userId, NotificationCategory $category, array $channels): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->setCategoryChannels($category, $channels);
        $this->preferences->save($preference);

        return $preference;
    }

    public function setQuietHours(string $userId, QuietHours $quietHours): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->setQuietHours($quietHours);
        $this->preferences->save($preference);

        return $preference;
    }

    public function setLanguage(string $userId, string $language): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->setLanguage($language);
        $this->preferences->save($preference);

        return $preference;
    }

    public function setFrequency(string $userId, int $maxPerDay): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->setMaxPerDay($maxPerDay);
        $this->preferences->save($preference);

        return $preference;
    }
}
