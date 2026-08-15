<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Verification\Contracts\VerificationStatusQuery;

/**
 * The rider is who they say they are — asked of M24, never re-derived here.
 *
 * Mandatory, and `blocksSubject()` rather than `isVerified()` on purpose:
 * whether an unverified rider is actually *blocked* also depends on whether the
 * platform is enforcing verification for that population yet, and that policy
 * belongs to the context that owns it. Checking verification status directly
 * would enforce ahead of M24's own rollout switch.
 */
final readonly class RiderIdentityIsVerified implements EligibilityRule
{
    public function __construct(private VerificationStatusQuery $verification)
    {
    }

    public function key(): string
    {
        return 'rider_identity_verified';
    }

    public function isMandatory(): bool
    {
        return true;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return $this->verification->blocksSubject('rider', $candidate->userId)
            ? RejectionReason::RiderNotVerified
            : null;
    }
}
