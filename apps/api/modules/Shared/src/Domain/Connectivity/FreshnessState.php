<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Connectivity;

/**
 * How much a piece of data can be trusted to describe the present.
 *
 * ## Why the server has an opinion about this at all
 *
 * Freshness sounds like a client concern — the phone knows whether it has
 * signal. But the client only knows whether *it* reached *us*. It cannot know
 * that the rider position we just returned was last heard four minutes ago, or
 * that the delivery fee came from a cached route rather than a live one. If the
 * server returns bare values, a perfectly-connected app will render stale data
 * as live, confidently.
 *
 * So every value whose age matters travels with this state and the instant it
 * was observed. The client combines it with its own connectivity to decide what
 * to show. Two independent signals, neither guessing at the other.
 *
 * ## The four states
 *
 * - `Online` — observed recently enough to act on.
 * - `Degraded` — older than we would like, still usable, must be labelled.
 *   This is the state that stops a binary from lying: a four-minute-old rider
 *   position is not live, but it is not nothing either.
 * - `Offline` — the source is known to be unreachable. A *positive* fact.
 * - `StaleUnknown` — we do not know how old this is, or whether the source is
 *   reachable. The honest answer when there is no answer, and deliberately
 *   distinct from `Offline`: "the rider's phone is off" and "we have not
 *   checked" are different things to tell an operator.
 *
 * `StaleUnknown` is the default everywhere. A value with no freshness
 * information must never default to `Online`.
 */
enum FreshnessState: string
{
    case Online = 'online';
    case Degraded = 'degraded';
    case Offline = 'offline';
    case StaleUnknown = 'stale_unknown';

    /**
     * Whether this may be presented as current, without qualification.
     *
     * Only `Online`. Every other state has to be shown with its age or with an
     * explicit caveat — that is the whole contract.
     */
    public function mayBePresentedAsLive(): bool
    {
        return $this === self::Online;
    }

    /**
     * Whether the value is worth showing at all, with a caveat.
     *
     * `Degraded` data is useful: a slightly old rider position still tells a
     * customer roughly where their food is. `Offline` and `StaleUnknown` mean we
     * have nothing current enough to stand behind.
     */
    public function isUsableWithCaveat(): bool
    {
        return $this === self::Online || $this === self::Degraded;
    }

    /**
     * Whether a *decision* may rest on this.
     *
     * Stricter than display. Showing a customer an old position is a small
     * discourtesy; dispatching against one sends a rider from where they used
     * to be. M26's `LocationIsFresh` eligibility rule already enforces this for
     * dispatch; this makes the same judgement available everywhere else.
     */
    public function isSafeToActOn(): bool
    {
        return $this === self::Online;
    }

    /** Operator- and customer-facing wording. */
    public function explain(): string
    {
        return match ($this) {
            self::Online => 'Current.',
            self::Degraded => 'Older than expected — shown with its age.',
            self::Offline => 'The source is not reachable.',
            self::StaleUnknown => 'Age unknown; treat as out of date.',
        };
    }
}
