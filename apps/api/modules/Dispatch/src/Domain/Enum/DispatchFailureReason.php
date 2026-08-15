<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/** Why a dispatch attempt, or a whole request, did not produce an assignment. */
enum DispatchFailureReason: string
{
    case NoCandidatesInRange = 'no_candidates_in_range';
    case NoEligibleRiders = 'no_eligible_riders';
    case OfferDeclined = 'offer_declined';
    case OfferExpired = 'offer_expired';
    case RoutingUnavailable = 'routing_unavailable';
    case AssignmentConflict = 'assignment_conflict';
    case RiderBecameIneligible = 'rider_became_ineligible';
    case MaxAttemptsExhausted = 'max_attempts_exhausted';
    case TimeBudgetExhausted = 'time_budget_exhausted';
    case Cancelled = 'cancelled';

    /**
     * Whether retrying could plausibly succeed.
     *
     * A declined offer is worth retrying with somebody else; an exhausted
     * attempt budget is not, and retrying it forever is how a customer waits
     * an hour for an answer nobody was ever going to give.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::OfferDeclined, self::OfferExpired, self::AssignmentConflict,
            self::RiderBecameIneligible, self::RoutingUnavailable => true,
            default => false,
        };
    }

    /** Whether operations should be told immediately rather than at the daily review. */
    public function warrantsAlert(): bool
    {
        return match ($this) {
            self::MaxAttemptsExhausted, self::TimeBudgetExhausted,
            self::NoEligibleRiders, self::NoCandidatesInRange => true,
            default => false,
        };
    }
}
