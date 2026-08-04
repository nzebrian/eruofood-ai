<?php

declare(strict_types=1);

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\NotificationStatus;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Notifications\Domain\Exception\NotificationsInvalidState;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Domain\ValueObject\RenderedContent;

function newNotification(NotificationChannel $channel = NotificationChannel::InApp): Notification
{
    return Notification::create(
        'n1',
        'user-1',
        NotificationCategory::Order,
        $channel,
        'order_placed',
        ['order_id' => 'o1'],
        new RenderedContent('Order received', 'Thanks'),
        Priority::Normal,
        null,
        new DateTimeImmutable('2026-08-01T10:00:00Z'),
    );
}

it('walks queued → sent → delivered and emits a dispatched event', function (): void {
    $n = newNotification();
    $n->queue(new DateTimeImmutable());
    $n->markSent(new DateTimeImmutable());
    expect($n->status())->toBe(NotificationStatus::Sent)
        ->and($n->attempts())->toBe(1)
        ->and($n->releaseEvents())->toHaveCount(1);
    $n->markDelivered(new DateTimeImmutable());
    expect($n->status())->toBe(NotificationStatus::Delivered);
});

it('blocks illegal transitions', function (): void {
    $n = newNotification();
    expect(fn () => $n->markDelivered(new DateTimeImmutable()))->toThrow(NotificationsInvalidState::class);
});

it('retries a failed notification under the cap', function (): void {
    $n = newNotification();
    $n->queue(new DateTimeImmutable());
    $n->markFailed('smtp down', new DateTimeImmutable());
    expect($n->status())->toBe(NotificationStatus::Failed)
        ->and($n->canRetry(3))->toBeTrue();
    $n->queue(new DateTimeImmutable()); // retry
    $n->markFailed('again', new DateTimeImmutable());
    $n->queue(new DateTimeImmutable());
    $n->markFailed('again', new DateTimeImmutable());
    expect($n->attempts())->toBe(3)->and($n->canRetry(3))->toBeFalse();
});

it('is read-once', function (): void {
    $n = newNotification();
    $n->markRead(new DateTimeImmutable('2026-08-01T11:00:00Z'));
    $first = $n->readAt();
    $n->markRead(new DateTimeImmutable('2026-08-01T12:00:00Z'));
    expect($n->readAt())->toBe($first)->and($n->isRead())->toBeTrue();
});

it('respects a scheduled time for due-ness', function (): void {
    $future = Notification::create(
        'n2',
        'u1',
        NotificationCategory::Promotional,
        NotificationChannel::Push,
        'broadcast',
        [],
        new RenderedContent('s', 'b'),
        Priority::Normal,
        new DateTimeImmutable('2026-08-02T10:00:00Z'),
        new DateTimeImmutable('2026-08-01T10:00:00Z'),
    );
    expect($future->isDue(new DateTimeImmutable('2026-08-01T12:00:00Z')))->toBeFalse()
        ->and($future->isDue(new DateTimeImmutable('2026-08-02T11:00:00Z')))->toBeTrue();
});
