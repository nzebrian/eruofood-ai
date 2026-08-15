<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * One reason a rider might not be offered this delivery.
 *
 * Eligibility is a chain of small, separately testable rules rather than one
 * long condition, for two reasons that both matter in production:
 *
 * 1. **Each rejection has a name.** "No eligible riders" is useless to an
 *    operator; "nine stale locations, two expired insurance" is a next action.
 *    A single boolean cannot produce that.
 * 2. **Rules can be turned off per market — except the ones that cannot.**
 *    {@see isMandatory()} marks the rules that are never optional, and
 *    `EligibilityService` ignores any configuration that tries to disable them.
 *
 * Returning `null` means "this rule has no objection", not "eligible": every
 * rule must pass.
 */
interface EligibilityRule
{
    /** Stable key, matching `config('dispatch.eligibility')`. */
    public function key(): string;

    /**
     * Whether this rule can be switched off.
     *
     * The three mandatory ones — identity verification, vehicle verification,
     * document currency — answer "is this person allowed to be doing this
     * work?". A flag that switches that off is a flag somebody will eventually
     * set, at 2am, to clear a backlog.
     */
    public function isMandatory(): bool;

    /** The reason this rider is out, or null if this rule has no objection. */
    public function evaluate(
        RiderCandidate $candidate,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): ?RejectionReason;
}
