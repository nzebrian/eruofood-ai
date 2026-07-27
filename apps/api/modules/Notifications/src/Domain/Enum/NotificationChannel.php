<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/** A delivery channel. WhatsApp & Telegram are architecture-ready. */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case InApp = 'in_app';
    case WhatsApp = 'whatsapp'; // architecture-ready
    case Telegram = 'telegram'; // architecture-ready

    /** In-app notifications are always deliverable (the notification centre). */
    public function isAlwaysOn(): bool
    {
        return $this === self::InApp;
    }
}
