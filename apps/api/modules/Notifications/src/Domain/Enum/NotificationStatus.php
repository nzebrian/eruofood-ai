<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/**
 * The lifecycle of a notification.
 *
 * ## What the three new states buy
 *
 * The original chain was `pending → queued → sent → delivered`, with `failed`
 * retryable back to `queued`. Two things were missing and one was wrong.
 *
 * - **`Sending`** separates "handed to the channel" from "the channel accepted
 *   it". Without it, a provider call that hangs leaves a notification sitting in
 *   `queued` indistinguishable from one nobody has picked up, and a retry sweep
 *   cannot tell whether sending it again would duplicate the message.
 *
 * - **`Retrying`** separates "failed and will be tried again" from "failed".
 *   An operator looking at a failure count needs to know which of them are
 *   still being worked.
 *
 * - **`PermanentlyFailed`** is the one that was *wrong*. `Failed → Queued` with
 *   no ceiling means a notification to a dead address is retried for ever,
 *   burning provider quota and keeping a row in the working set indefinitely.
 *   Permanent failure is terminal, and reaching it is a decision the platform
 *   records rather than a state it drifts into.
 *
 * `Delivered` and `PermanentlyFailed` are both terminal. Nothing leaves them.
 *
 * ## Notifications never roll anything back
 *
 * Every state here is downstream of the thing that caused it. An order, a
 * payment, a dispatch assignment or a delivery transition is complete before
 * its notification is attempted, and no failure in this enum may reverse one.
 * See `NotificationFailureIsolationTest`.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case PermanentlyFailed = 'permanently_failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            // `Queued → Sent` is retained alongside the new `Sending` step so
            // channels that report synchronously keep working unchanged.
            self::Pending => in_array($next, [self::Queued, self::Failed], true),
            self::Queued => in_array($next, [self::Sending, self::Sent, self::Failed], true),
            self::Sending => in_array($next, [self::Sent, self::Failed], true),
            self::Sent => in_array($next, [self::Delivered, self::Failed], true),
            // A failure is either scheduled for another attempt or declared
            // permanent. `Failed → Queued` is kept so existing retry paths are
            // unaffected.
            self::Failed => in_array($next, [self::Queued, self::Retrying, self::PermanentlyFailed], true),
            self::Retrying => in_array($next, [self::Sending, self::Sent, self::Failed, self::PermanentlyFailed], true),
            self::Delivered, self::PermanentlyFailed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::PermanentlyFailed;
    }

    /** Whether another attempt is still permitted from here. */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Retrying;
    }

    /** Whether the platform is still trying to deliver this. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Pending, self::Queued, self::Sending, self::Sent, self::Retrying => true,
            self::Delivered, self::Failed, self::PermanentlyFailed => false,
        };
    }
}
