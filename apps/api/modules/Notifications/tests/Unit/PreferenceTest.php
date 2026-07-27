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

it('honours per-category channel overrides but keeps in-app on', function (): void {
    $p = prefs();
    $p->setCategoryChannels(NotificationCategory::Order, [NotificationChannel::Push]);
    expect($p->allows(NotificationCategory::Order, NotificationChannel::Email))->toBeFalse()
        ->and($p->allows(NotificationCategory::Order, NotificationChannel::Push))->toBeTrue()
        ->and($p->allows(NotificationCategory::Order, NotificationChannel::InApp))->toBeTrue();
});

it('detects quiet hours across midnight', function (): void {
    $q = new QuietHours(true, '22:00', '07:00');
    expect($q->isWithin(new DateTimeImmutable('2026-08-01T23:30:00')))->toBeTrue()
        ->and($q->isWithin(new DateTimeImmutable('2026-08-01T06:30:00')))->toBeTrue()
        ->and($q->isWithin(new DateTimeImmutable('2026-08-01T12:00:00')))->toBeFalse();
});
