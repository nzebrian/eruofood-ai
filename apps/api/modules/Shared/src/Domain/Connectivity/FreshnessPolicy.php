<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Connectivity;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * How old is too old, for one particular kind of data.
 *
 * There is no single right answer, which is why this is a value rather than a
 * constant. A rider position goes stale in minutes; a merchant's opening hours
 * are fine for a day. Hard-coding one threshold would either make opening hours
 * permanently "degraded" or make a four-minute-old rider position look live.
 *
 * ## Two thresholds, not one
 *
 * `liveWithin` — still current; act on it.
 * `usableWithin` — old, still worth showing, must be labelled with its age.
 * Beyond that — `StaleUnknown`; we no longer stand behind it.
 *
 * The middle band is the point. A single cutoff forces a binary choice between
 * claiming freshness we do not have and discarding data that is still useful,
 * and both of those are worse than saying "four minutes old".
 */
final readonly class FreshnessPolicy
{
    private function __construct(
        public int $liveWithinSeconds,
        public int $usableWithinSeconds,
    ) {
    }

    public static function of(int $liveWithinSeconds, int $usableWithinSeconds): self
    {
        if ($liveWithinSeconds <= 0) {
            throw new InvalidArgumentException('The live window must be positive.');
        }

        if ($usableWithinSeconds < $liveWithinSeconds) {
            // Otherwise the degraded band is empty or inverted, and data would
            // jump straight from live to unknown.
            throw new InvalidArgumentException(
                'The usable window must be at least as long as the live window.',
            );
        }

        return new self($liveWithinSeconds, $usableWithinSeconds);
    }

    /**
     * Rider positions, aligned with M25's own staleness setting.
     *
     * The live window is `geo.privacy.rider_location_stale_seconds`, the same
     * value M26's `LocationIsFresh` eligibility rule uses — so a rider who is
     * too stale to dispatch to is also too stale to describe as live on a
     * customer's map. Two components disagreeing about what "fresh" means is
     * how a customer watches a rider who is not moving.
     */
    public static function riderPosition(int $staleAfterSeconds): self
    {
        return self::of($staleAfterSeconds, $staleAfterSeconds * 4);
    }

    public function judge(int $ageSeconds): FreshnessState
    {
        if ($ageSeconds < 0) {
            // A future timestamp means a clock disagreement somewhere, and the
            // one thing it is not is evidence of freshness.
            return FreshnessState::StaleUnknown;
        }

        return match (true) {
            $ageSeconds <= $this->liveWithinSeconds => FreshnessState::Online,
            $ageSeconds <= $this->usableWithinSeconds => FreshnessState::Degraded,
            default => FreshnessState::StaleUnknown,
        };
    }
}
