<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/**
 * The category a notification belongs to. Users can enable/disable channels per
 * category (preferences), and promotional/admin categories drive campaigns.
 *
 * A category also determines the notification's {@see NotificationClass}, which
 * is what decides consent, unsubscribe handling and suppression.
 */
enum NotificationCategory: string
{
    case Account = 'account';         // registration, login, password, 2FA
    case Order = 'order';
    case Payment = 'payment';
    case Wallet = 'wallet';
    case Delivery = 'delivery';
    case Promotional = 'promotional'; // campaigns
    case Ai = 'ai';                   // recommendations
    case Nutrition = 'nutrition';     // meal/nutrition reminders
    case Admin = 'admin';             // broadcasts
    case Verification = 'verification'; // KYC / KYB identity verification (M24)

    /** Whether this category is subject to quiet hours (transactional ones are not). */
    public function respectsQuietHours(): bool
    {
        return in_array($this, [self::Promotional, self::Ai, self::Nutrition], true);
    }
}
