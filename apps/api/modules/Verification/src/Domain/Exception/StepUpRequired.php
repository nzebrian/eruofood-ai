<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The operation needs a stronger identity assurance than the account currently
 * holds.
 *
 * Carries the level required so the client can send the user to the right flow
 * instead of guessing. This is a 403 with a machine-readable next step, not a
 * failure.
 */
final class StepUpRequired extends DomainException
{
    private function __construct(string $message, public readonly string $requiredLevel, public readonly string $trigger)
    {
        parent::__construct($message);
    }

    public static function level(string $requiredLevel, string $trigger): self
    {
        return new self(
            sprintf('This action requires "%s" verification before it can proceed.', $requiredLevel),
            $requiredLevel,
            $trigger,
        );
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_STEP_UP_REQUIRED';
    }
}
