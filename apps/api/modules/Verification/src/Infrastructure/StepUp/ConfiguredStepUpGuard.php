<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\StepUp;

use EruoFood\Verification\Application\Service\StepUpService;
use EruoFood\Verification\Contracts\StepUpGuard;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Enum\VerificationLevel;

/**
 * Joins the two halves of a step-up decision: what the operation demands
 * ({@see StepUpService}) and what the account currently holds
 * ({@see VerificationStatusQuery}).
 *
 * Kept out of the Application layer because it is the adapter consuming
 * contexts bind to — Payments depends on the published {@see StepUpGuard}
 * interface, never on Verification's services.
 */
final readonly class ConfiguredStepUpGuard implements StepUpGuard
{
    public function __construct(
        private StepUpService $stepUp,
        private VerificationStatusQuery $verification,
    ) {
    }

    public function assert(string $trigger, string $userId, ?int $amountMinor = null, ?int $riskCount = null): void
    {
        $required = $this->stepUp->requiredLevelFor($trigger, $amountMinor, $riskCount);

        if ($required === null) {
            return;
        }

        // The level is read only once the trigger has actually fired, so an
        // ungated operation costs no query at all.
        $current = VerificationLevel::tryFrom($this->verification->levelFor($userId))
            ?? VerificationLevel::Basic;

        $this->stepUp->assert($trigger, $current, $amountMinor, $riskCount);
    }

    public function requiredLevelFor(string $trigger, ?int $amountMinor = null, ?int $riskCount = null): ?string
    {
        return $this->stepUp->requiredLevelFor($trigger, $amountMinor, $riskCount)?->value;
    }
}
