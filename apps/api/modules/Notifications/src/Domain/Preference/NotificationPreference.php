<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Preference;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\NotificationClass;
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
        private bool $marketingOptIn = false,
        private ?DateTimeImmutable $marketingOptInAt = null,
        private ?string $unsubscribeToken = null,
    ) {
    }

    public static function defaults(string $userId, string $language, QuietHours $quietHours): self
    {
        // Marketing defaults to off. A positive choice is what makes it consent;
        // defaulting an existing user base to opted-in because a column was
        // added would manufacture agreement nobody gave.
        return new self($userId, [], $quietHours, $language, 0, marketingOptIn: false);
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
        bool $marketingOptIn = false,
        ?DateTimeImmutable $marketingOptInAt = null,
        ?string $unsubscribeToken = null,
    ): self {
        return new self(
            $userId,
            $channelsByCategory,
            $quietHours,
            $language,
            $maxPerDay,
            $marketingOptIn,
            $marketingOptInAt,
            $unsubscribeToken,
        );
    }

    /**
     * Whether a channel is enabled for a category.
     *
     * When the user has set an explicit channel list for a category, it is
     * honoured exactly — a channel omitted from that list is disabled, including
     * in-app. When a category has no explicit configuration, sensible defaults
     * apply: in-app is always on, and every other channel is on except
     * promotional SMS. This lets a user genuinely restrict a category (e.g.
     * "payments by email only") while still receiving in-app notifications by
     * default for categories they have not customised.
     */
    public function allows(NotificationCategory $category, NotificationChannel $channel): bool
    {
        $configured = $this->channelsByCategory[$category->value] ?? null;
        if ($configured === null) {
            // Unconfigured default: in-app always on; promotional SMS off; the rest on.
            if ($channel->isAlwaysOn()) {
                return true;
            }

            return ! ($category === NotificationCategory::Promotional && $channel === NotificationChannel::Sms);
        }

        // Explicit per-category configuration is honoured exactly.
        return in_array($channel->value, $configured, true);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    public function setCategoryChannels(NotificationCategory $category, array $channels): void
    {
        $this->channelsByCategory[$category->value] = array_map(
            static fn (NotificationChannel $c): string => $c->value,
            $channels,
        );
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

    /**
     * Whether a message of this class may be sent at all.
     *
     * Security messages are never suppressed. A user who "unsubscribed" from
     * password-reset and verification notices has not been served, they have
     * been abandoned — and an attacker who can silence those has removed the
     * platform's only way of telling somebody their account is under attack.
     */
    public function permits(NotificationClass $class): bool
    {
        if ($class === NotificationClass::Security) {
            return true;
        }

        return ! $class->requiresOptIn() || $this->marketingOptIn;
    }

    /** Record an explicit opt-in, with the moment a consent audit will ask about. */
    public function optIntoMarketing(DateTimeImmutable $at): void
    {
        $this->marketingOptIn = true;
        $this->marketingOptInAt = $at;
    }

    /**
     * Withdraw marketing consent.
     *
     * Deliberately does not touch any other category: an unsubscribe link in a
     * campaign must not also stop delivery receipts or security alerts.
     */
    public function optOutOfMarketing(): void
    {
        $this->marketingOptIn = false;
        $this->marketingOptInAt = null;
    }

    /**
     * The token that makes an unsubscribe link work from an email client with no
     * session — the one request the platform must honour without normal
     * authentication. Random and revocable, so a leaked link costs a regenerated
     * token rather than an account.
     */
    public function unsubscribeToken(): ?string
    {
        return $this->unsubscribeToken;
    }

    public function assignUnsubscribeToken(string $token): void
    {
        $this->unsubscribeToken ??= $token;
    }

    public function marketingOptIn(): bool
    {
        return $this->marketingOptIn;
    }

    public function marketingOptInAt(): ?DateTimeImmutable
    {
        return $this->marketingOptInAt;
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
