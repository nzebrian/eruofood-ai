<?php

declare(strict_types=1);

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Preference\NotificationPreference;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;

function prefs(): NotificationPreference
{
    return NotificationPreference::defaults('u1', 'en', QuietHours::disabled());
}

it('allows everything by default except promotional SMS, and in-app always', function (): void {
    $p = prefs();
    expect($p->allows(NotificationCategory::Order, NotificationChannel::Email))->toBeTrue()
        ->and($p->allows(NotificationCategory::Promotional, NotificationChannel::Sms))->toBeFalse()
        ->and($p->allows(NotificationCategory::Promotional, NotificationChannel::InApp))->toBeTrue();
});

it('honours per-category channel overrides exactly, including disabling in-app', function (): void {
    $p = prefs();
    // An explicit override that omits in-app disables it for that category.
    $p->setCategoryChannels(NotificationCategory::Order, [NotificationChannel::Push]);
    expect($p->allows(NotificationCategory::Order, NotificationChannel::Email))->toBeFalse()
        ->and($p->allows(NotificationCategory::Order, NotificationChannel::Push))->toBeTrue()
        ->and($p->allows(NotificationCategory::Order, NotificationChannel::InApp))->toBeFalse()
        // A category the user has NOT customised still gets in-app by default.
        ->and($p->allows(NotificationCategory::Payment, NotificationChannel::InApp))->toBeTrue();

    // Restricting a category to email-only silences push and in-app for it.
    $p->setCategoryChannels(NotificationCategory::Payment, [NotificationChannel::Email]);
    expect($p->allows(NotificationCategory::Payment, NotificationChannel::Email))->toBeTrue()
        ->and($p->allows(NotificationCategory::Payment, NotificationChannel::Push))->toBeFalse()
        ->and($p->allows(NotificationCategory::Payment, NotificationChannel::InApp))->toBeFalse();
});

it('detects quiet hours across midnight', function (): void {
    $q = new QuietHours(true, '22:00', '07:00');
    expect($q->isWithin(new DateTimeImmutable('2026-08-01T23:30:00')))->toBeTrue()
        ->and($q->isWithin(new DateTimeImmutable('2026-08-01T06:30:00')))->toBeTrue()
        ->and($q->isWithin(new DateTimeImmutable('2026-08-01T12:00:00')))->toBeFalse();
});
