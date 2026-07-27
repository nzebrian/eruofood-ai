<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Preference;

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;

/**
 * A user's notification preferences: which channels are enabled per category,
 * their quiet-hours window, language, and a per-category frequency cap. The
 * in-app channel is always deliverable; disabling it only hides toasts, never
 * the notification centre. Sensible defaults apply until a user customises.
 */
final class NotificationPreference
{
    /**
     * @param array<string, list<string>> $channelsByCategory category => enabled channel values
     */
    private function __construct(
        private readonly string $userId,
        private array $channelsByCategory,
        private QuietHours $quietHours,
        private string $language,
        private int $maxPerDay,
    ) {
    }

    public static function defaults(string $userId, string $language, QuietHours $quietHours): self
    {
        return new self($userId, [], $quietHours, $language, 0);
    }

    /**
     * @param array<string, list<string>> $channelsByCategory
     */
    public static function reconstitute(
        string $userId,
        array $channelsByCategory,
        QuietHours $quietHours,
        string $language,
        int $maxPerDay,
    ): self {
        return new self($userId, $channelsByCategory, $quietHours, $language, $maxPerDay);
    }

    /** Whether a channel is enabled for a category (default: on, except promo SMS). */
    public function allows(NotificationCategory $category, NotificationChannel $channel): bool
    {
        if ($channel->isAlwaysOn()) {
            return true;
        }
        $configured = $this->channelsByCategory[$category->value] ?? null;
        if ($configured === null) {
            // Default: promotional SMS off; everything else on.
            return ! ($category === NotificationCategory::Promotional && $channel === NotificationChannel::Sms);
        }

        return in_array($channel->value, $configured, true);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    public function setCategoryChannels(NotificationCategory $category, array $channels): void
    {
        $this->channelsByCategory[$category->value] = array_values(array_map(
            static fn (NotificationChannel $c): string => $c->value,
            $channels,
        ));
    }

    public function setQuietHours(QuietHours $quietHours): void
    {
        $this->quietHours = $quietHours;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function setMaxPerDay(int $maxPerDay): void
    {
        $this->maxPerDay = max(0, $maxPerDay);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** @return array<string, list<string>> */
    public function channelsByCategory(): array
    {
        return $this->channelsByCategory;
    }

    public function quietHours(): QuietHours
    {
        return $this->quietHours;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function maxPerDay(): int
    {
        return $this->maxPerDay;
    }
}
