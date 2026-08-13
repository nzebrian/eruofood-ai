<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * A caller tried to move a case into a state it cannot legitimately reach.
 *
 * This is thrown rather than logged because an illegal transition on a
 * verification case means something is wrong with the caller — a mis-mapped
 * provider status, a replayed decision, a bug in review handling — and quietly
 * allowing it would let a rejected rider drift into verified.
 */
final class InvalidVerificationTransition extends DomainException
{
    public static function between(VerificationStatus $from, VerificationStatus $to, string $caseId): self
    {
        return new self(sprintf(
            'Verification case "%s" cannot move from "%s" to "%s".',
            $caseId,
            $from->value,
            $to->value,
        ));
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_INVALID_TRANSITION';
    }
}
