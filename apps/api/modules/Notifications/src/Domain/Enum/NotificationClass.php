<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/**
 * What kind of message this is, in the sense that consent law and good practice
 * care about.
 *
 * The distinction is not cosmetic — it decides three things:
 *
 * - **Consent.** Marketing requires an explicit opt-in and honours an
 *   unsubscribe. Transactional and security mail does not, because a customer
 *   who "unsubscribed" from password-reset notices has not been served, they
 *   have been abandoned.
 * - **Unsubscribe headers.** Attached to marketing only. Offering one-click opt
 *   out of a security alert would be worse than useless.
 * - **Suppression.** A user's channel preferences can silence marketing
 *   entirely; security messages are the one class that always goes out on at
 *   least one channel.
 *
 * Derived from the category rather than passed around separately, so a new
 * category cannot accidentally arrive unclassified.
 */
enum NotificationClass: string
{
    /** Something the user did, or something that happened to their order/money. */
    case Transactional = 'transactional';

    /** Account safety: credentials, sessions, verification, access. */
    case Security = 'security';

    /** Campaigns and promotions. Opt-in, and always unsubscribable. */
    case Marketing = 'marketing';

    public static function forCategory(NotificationCategory $category): self
    {
        return match ($category) {
            NotificationCategory::Account,
            NotificationCategory::Verification => self::Security,
            NotificationCategory::Promotional => self::Marketing,
            default => self::Transactional,
        };
    }

    /** Whether the user must have opted in before this may be sent. */
    public function requiresOptIn(): bool
    {
        return $this === self::Marketing;
    }

    /** Whether an unsubscribe affects this class. */
    public function honoursUnsubscribe(): bool
    {
        return $this === self::Marketing;
    }

    /** Whether `List-Unsubscribe` headers belong on the message. */
    public function carriesUnsubscribeHeaders(): bool
    {
        return $this === self::Marketing;
    }
}
