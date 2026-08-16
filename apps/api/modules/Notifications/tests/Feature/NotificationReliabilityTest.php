<?php

declare(strict_types=1);

use EruoFood\Notifications\Domain\Enum\NotificationStatus;

/**
 * Notification reliability, and the boundary it must never cross.
 *
 * A notification is a *downstream side effect*. The order was placed, the money
 * moved, the rider accepted — and then we tried to tell somebody. If telling
 * them fails, the thing that happened still happened. A notification failure
 * that rolls back an order is the single worst outcome available here, and it is
 * not hypothetical: M26 shipped with exactly that defect, where the container
 * resolved the notification service *outside* the try/catch that was supposed
 * to contain it.
 */

// --------------------------------------------------------- permanent failure

it('makes permanent failure terminal so a dead address is not retried for ever', function (): void {
    // The defect in the original lifecycle: `Failed → Queued` with no ceiling
    // meant a notification to a dead address was retried indefinitely, burning
    // provider quota and never leaving the working set.
    expect(NotificationStatus::PermanentlyFailed->isTerminal())->toBeTrue()
        ->and(NotificationStatus::PermanentlyFailed->isRetryable())->toBeFalse();

    foreach (NotificationStatus::cases() as $next) {
        expect(NotificationStatus::PermanentlyFailed->canTransitionTo($next))
            ->toBeFalse("PermanentlyFailed must not transition to {$next->value}.");
    }
});

it('lets a failure be declared permanent rather than retried', function (): void {
    expect(NotificationStatus::Failed->canTransitionTo(NotificationStatus::PermanentlyFailed))->toBeTrue()
        ->and(NotificationStatus::Retrying->canTransitionTo(NotificationStatus::PermanentlyFailed))->toBeTrue();
});

it('keeps delivered terminal', function (): void {
    expect(NotificationStatus::Delivered->isTerminal())->toBeTrue();

    foreach (NotificationStatus::cases() as $next) {
        expect(NotificationStatus::Delivered->canTransitionTo($next))->toBeFalse();
    }
});

// ------------------------------------------------------- the new intermediates

it('separates handed-to-the-channel from accepted-by-the-channel', function (): void {
    // Without `Sending`, a provider call that hangs leaves the row in `Queued`,
    // indistinguishable from one nobody has picked up — and a retry sweep
    // cannot tell whether sending again would duplicate the message.
    expect(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Sending))->toBeTrue()
        ->and(NotificationStatus::Sending->canTransitionTo(NotificationStatus::Sent))->toBeTrue()
        ->and(NotificationStatus::Sending->canTransitionTo(NotificationStatus::Failed))->toBeTrue()
        ->and(NotificationStatus::Sending->isInFlight())->toBeTrue();
});

it('separates a failure being retried from one that is simply failed', function (): void {
    expect(NotificationStatus::Failed->canTransitionTo(NotificationStatus::Retrying))->toBeTrue()
        ->and(NotificationStatus::Retrying->isInFlight())->toBeTrue()
        ->and(NotificationStatus::Failed->isInFlight())->toBeFalse();
});

it('preserves the transitions that shipped before this change', function (): void {
    // M24 and M26 notification paths use these. Extending a lifecycle must not
    // invalidate the one already in production.
    expect(NotificationStatus::Pending->canTransitionTo(NotificationStatus::Queued))->toBeTrue()
        ->and(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Sent))->toBeTrue()
        ->and(NotificationStatus::Sent->canTransitionTo(NotificationStatus::Delivered))->toBeTrue()
        ->and(NotificationStatus::Failed->canTransitionTo(NotificationStatus::Queued))->toBeTrue();
});

it('refuses to skip straight from queued to delivered', function (): void {
    // Delivery is something a channel reports, not something the platform may
    // assume — the same rule as a client not claiming server success.
    expect(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Delivered))->toBeFalse()
        ->and(NotificationStatus::Pending->canTransitionTo(NotificationStatus::Sent))->toBeFalse();
});

it('never reports an in-flight notification as terminal', function (): void {
    foreach (NotificationStatus::cases() as $status) {
        if ($status->isInFlight()) {
            expect($status->isTerminal())->toBeFalse("{$status->value} cannot be both in flight and terminal.");
        }
    }
});

it('keeps the retry ceiling configurable and off by default', function (): void {
    // Automatic retry ships disabled, like every other high-risk capability.
    expect(app(EruoFood\Shared\Domain\Flag\FlagEvaluator::class)->isEnabled('notifications.retry'))
        ->toBeFalse();
});
