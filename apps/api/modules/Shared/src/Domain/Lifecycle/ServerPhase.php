<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Lifecycle;

/**
 * The coarse, platform-wide answer to "where has this actually got to?"
 *
 * ## The problem this solves
 *
 * A phone loses signal halfway through checkout. The app has to tell somebody
 * whether their money moved. It cannot know — it never heard back — and the
 * dangerous failure is an app that assumes success because the button was
 * pressed, or assumes failure and lets the customer pay twice.
 *
 * Each context already answers this precisely in its own vocabulary: Commerce
 * has `paid`, Payments has `succeeded`, Dispatch has `accepted`, Marketplace has
 * `picked_up`. Thirty enums, all correct, none comparable. A client that wants
 * one honest sentence has to understand all thirty and re-derive the answer —
 * which means the client is deciding, which is exactly what must not happen.
 *
 * So this is a *projection*, not a replacement. Every context keeps its own
 * state machine and its own transitions; each one additionally says which of
 * these eight phases its states correspond to. The client reads the phase and
 * the context's own status: the phase tells it what to render, the status tells
 * it what to say.
 *
 * ## The rule that makes it safe
 *
 * **A phase is only ever computed on the server, from server state.** It is
 * output, never input. Nothing accepts a phase from a request body, and
 * {@see ServerAuthoritative} exists so that a context enum has to declare its
 * mapping rather than a client inferring one. An app may cache the last phase it
 * was told; it may not invent a new one, and a cached phase is stale data —
 * which is why it travels inside a freshness envelope rather than alone.
 *
 * ## Why these eight
 *
 * They are the distinctions that change what a person should *do*:
 *
 * - Not yet real, and safe to edit or abandon → `Draft`
 * - Handed over; we have it, nothing irreversible has happened → `Submitted`
 * - Accepted and waiting on something or someone → `Pending`
 * - Actively being worked, irreversible steps may be under way → `Processing`
 * - Done, and it worked → `Confirmed`
 * - Done, and it did not → `Failed`
 * - Stopped deliberately → `Cancelled`
 * - Stopped by the passage of time → `Expired`
 *
 * `Cancelled` and `Expired` are separate because "you cancelled this" and "you
 * took too long" are different things to tell somebody, and only one of them is
 * anybody's fault. `Failed` and `Expired` are separate for the same reason.
 */
enum ServerPhase: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Pending = 'pending';
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Whether the outcome is settled and will not change on its own.
     *
     * The question a client asks to decide between showing a spinner and
     * showing an answer.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Failed, self::Cancelled, self::Expired => true,
            self::Draft, self::Submitted, self::Pending, self::Processing => false,
        };
    }

    /**
     * Whether the operation succeeded.
     *
     * Deliberately narrow: only `Confirmed`. A client must not treat "we have
     * your request" as "your money moved", and this is the method that stops it
     * from trying.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Whether work is still in flight, so the answer may still change.
     *
     * A client polling or reconciling uses this to decide whether to keep
     * asking. It is not the negation of `isTerminal()` — `Draft` is neither in
     * flight nor settled; it is simply not started.
     */
    public function isInFlight(): bool
    {
        return $this === self::Submitted || $this === self::Pending || $this === self::Processing;
    }

    /**
     * Whether a client may safely retry the originating request.
     *
     * `Processing` is excluded on purpose, and it is the important exclusion: a
     * payment being captured at the provider may still succeed, and retrying it
     * is how a customer gets charged twice. A client that wants to know what
     * happened must reconcile, not retry.
     *
     * `Failed` and `Expired` are retryable because nothing took effect.
     * `Cancelled` is not — somebody decided; repeating the request would
     * override their decision.
     */
    public function isSafelyRetryable(): bool
    {
        return $this === self::Failed || $this === self::Expired;
    }

    /**
     * Whether an unconfirmed operation is still worth waiting on.
     *
     * The state a mobile client shows as "we are still checking" rather than
     * either outcome. Critical financial operations sit here until the server
     * confirms; they must never be rendered as success.
     */
    public function awaitsServerConfirmation(): bool
    {
        return $this->isInFlight();
    }

    /** Wording safe to show a customer, in the absence of anything better. */
    public function explain(): string
    {
        return match ($this) {
            self::Draft => 'Not submitted yet.',
            self::Submitted => 'Received. We have not started on it yet.',
            self::Pending => 'Accepted and waiting.',
            self::Processing => 'In progress. The outcome is not decided yet.',
            self::Confirmed => 'Completed successfully.',
            self::Failed => 'Did not complete.',
            self::Cancelled => 'Cancelled.',
            self::Expired => 'Expired before it could complete.',
        };
    }
}
