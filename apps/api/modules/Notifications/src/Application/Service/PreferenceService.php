<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use DateTimeImmutable;
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

    /**
     * Record an explicit opt-in to marketing.
     *
     * Issues the unsubscribe token at the same moment, so every marketing
     * message the platform is now allowed to send already has a working way out
     * of it. A campaign that goes out before its unsubscribe link exists is the
     * failure mode this ordering prevents.
     */
    public function optIntoMarketing(string $userId): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->optIntoMarketing(new DateTimeImmutable());
        $preference->assignUnsubscribeToken($this->newToken());
        $this->preferences->save($preference);

        return $preference;
    }

    public function optOutOfMarketing(string $userId): NotificationPreference
    {
        $preference = $this->get($userId);
        $preference->optOutOfMarketing();
        $this->preferences->save($preference);

        return $preference;
    }

    /**
     * Honour an unsubscribe link from an email client.
     *
     * Idempotent and silent about whether the token was real: an endpoint that
     * distinguishes "unsubscribed" from "no such token" lets anybody test tokens
     * against it. Either way the caller is told it is done.
     *
     * Only marketing is withdrawn. An unsubscribe from a campaign must never
     * silence delivery receipts or account-security messages — those are not
     * marketing, and the person clicking did not ask to stop receiving them.
     */
    public function unsubscribeByToken(string $token): bool
    {
        $preference = $this->preferences->forUnsubscribeToken($token);
        if ($preference === null) {
            return false;
        }

        $preference->optOutOfMarketing();
        $this->preferences->save($preference);

        return true;
    }

    /** The token that lets an unsubscribe link work without a session. */
    private function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
