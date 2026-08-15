<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * A rider was offered a delivery and, by the time they accepted, could no
 * longer take it.
 *
 * Distinct from {@see AssignmentConflict}, which means somebody else got there
 * first. This means nothing about the delivery changed — the *rider* did: their
 * insurance lapsed, an operator suspended them, M24 revoked their verification.
 *
 * The reason travels with it because "you cannot take this" is not an answer.
 * A rider whose earnings just stopped needs to know whether to renew a policy
 * or ring support, and {@see RejectionReason::isRiderActionable()} is what
 * decides which of those they are told.
 */
final class RiderNoLongerEligible extends DomainException
{
    private function __construct(string $message, public readonly RejectionReason $reason)
    {
        parent::__construct($message);
    }

    public static function because(RejectionReason $reason): self
    {
        return new self(
            sprintf(
                'You can no longer take this delivery: %s.',
                str_replace('_', ' ', $reason->value),
            ),
            $reason,
        );
    }

    public function errorCode(): string
    {
        return 'DISPATCH_RIDER_NO_LONGER_ELIGIBLE';
    }
}
