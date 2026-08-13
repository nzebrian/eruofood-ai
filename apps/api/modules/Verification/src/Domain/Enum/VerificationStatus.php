<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * The lifecycle of a verification case.
 *
 * The transition table below is the whole point of this enum: a verification
 * status decides whether a rider may take deliveries and whether a business may
 * take orders, so it must never be possible to move a case into a state it did
 * not legitimately reach. A provider webhook, an admin decision and an expiry
 * sweep all funnel through {@see canTransitionTo()}.
 */
enum VerificationStatus: string
{
    /** No case has been opened for this subject yet. */
    case NotStarted = 'not_started';

    /** A case exists and a provider session has been created; awaiting the subject. */
    case Pending = 'pending';

    /** The subject submitted; the provider is deciding. */
    case Processing = 'processing';

    /** The provider could not decide automatically — a human must look. */
    case RequiresReview = 'requires_review';

    /** Verified. The subject satisfies the requested level. */
    case Verified = 'verified';

    /** Rejected, with a classified reason. A fresh attempt may be started. */
    case Rejected = 'rejected';

    /** A previously good verification aged out. */
    case Expired = 'expired';

    /** Still on file, but the subject must verify again (data changed, policy change, provider resubmission). */
    case ReverificationRequired = 'reverification_required';

    /**
     * Whether a case in this status may move to $next.
     *
     * Same-status moves are allowed and treated as no-ops by the aggregate, so
     * a duplicate provider webhook does not become an error.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotStarted => [self::Pending],
            self::Pending => [self::Processing, self::RequiresReview, self::Verified, self::Rejected, self::Expired],
            self::Processing => [self::RequiresReview, self::Verified, self::Rejected, self::Expired],
            self::RequiresReview => [self::Verified, self::Rejected, self::Expired, self::ReverificationRequired],
            // A good verification can only decay, never silently un-verify.
            self::Verified => [self::Expired, self::ReverificationRequired],
            // A closed case reopens by starting a new attempt.
            self::Rejected, self::Expired, self::ReverificationRequired => [self::Pending],
        };
    }

    /** Whether the subject currently satisfies verification. */
    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /** Whether the platform is still waiting on the subject or the provider. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::RequiresReview], true);
    }

    /**
     * Whether a case in this status still occupies the subject's single open
     * slot. Closed statuses release it so a new attempt can begin.
     *
     * `NotStarted` counts. A case that has been created but not yet handed to a
     * provider is still this subject's unfinished case, and excluding it would
     * open the slot at exactly the moment cases are created: two taps on
     * "verify", or one retried request, would each get a fresh case and the
     * unique index would have nothing to catch.
     */
    public function isOpen(): bool
    {
        return $this === self::NotStarted || $this->isInFlight();
    }

    /** Whether a human reviewer needs to act. */
    public function needsReview(): bool
    {
        return $this === self::RequiresReview;
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
