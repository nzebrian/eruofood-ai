<?php

declare(strict_types=1);

namespace EruoFood\Verification\Contracts;

/**
 * The published way another context asks "does this operation need more
 * assurance from this account than it currently has?".
 *
 * Consumers name a trigger and pass whatever the trigger is scored on; they do
 * not decide what is sensitive, and they do not read verification levels
 * themselves. Which triggers demand what lives in `config/verification.php`,
 * because a transfer threshold is an operational judgement that changes with
 * fraud patterns and should not need a deploy.
 *
 * A trigger nobody has configured demands nothing. That default is deliberate:
 * a caller naming an unknown trigger should not silently lock a customer out of
 * an operation the platform never decided to gate.
 */
interface StepUpGuard
{
    /**
     * @param string $trigger configured trigger name, e.g. `wallet.transfer`
     * @param string $userId the account performing the operation
     * @param int|null $amountMinor for value-scored triggers
     * @param int|null $riskCount for counter-scored triggers
     *
     * @throws \EruoFood\Verification\Domain\Exception\StepUpRequired when the
     *                                                                account's level is insufficient. The exception carries the level
     *                                                                required, so a client can send the user to the right flow; the
     *                                                                app maps it to 403 with a machine-readable code.
     */
    public function assert(string $trigger, string $userId, ?int $amountMinor = null, ?int $riskCount = null): void;

    /** The level this operation would demand, or null if it demands nothing. */
    public function requiredLevelFor(string $trigger, ?int $amountMinor = null, ?int $riskCount = null): ?string;
}
