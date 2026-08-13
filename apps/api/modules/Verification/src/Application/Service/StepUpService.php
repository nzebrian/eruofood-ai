<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Exception\StepUpRequired;

/**
 * Decides when an operation needs stronger identity assurance than the account
 * currently holds.
 *
 * The triggers live in `config/verification.php`, not in code, because the
 * thresholds are an operational judgement that changes with fraud patterns —
 * demanding a deploy to raise a transfer limit would mean the limit is wrong for
 * as long as the release takes. Three trigger shapes cover what the platform
 * actually needs:
 *
 * - `always`      — the operation is sensitive regardless of size
 *   (changing bank details, changing the account email).
 * - `above_minor` — only past an amount (a large wallet transfer).
 * - `threshold`   — only once a risk counter reaches a level (disputes raised).
 *
 * An unknown trigger name is *not* an error and does not demand step-up: a
 * caller naming a trigger nobody configured should not silently block the user.
 * Configuration decides what is sensitive; code only asks.
 */
final readonly class StepUpService
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * Assert the account may perform this operation.
     *
     * @param int|null $amountMinor for value-based triggers
     * @param int|null $riskCount for counter-based triggers
     *
     * @throws StepUpRequired when the account's level is insufficient
     */
    public function assert(
        string $trigger,
        VerificationLevel $currentLevel,
        ?int $amountMinor = null,
        ?int $riskCount = null,
    ): void {
        $required = $this->requiredLevelFor($trigger, $amountMinor, $riskCount);

        if ($required === null || $currentLevel->satisfies($required)) {
            return;
        }

        throw StepUpRequired::level($required->value, $trigger);
    }

    /**
     * The level this operation demands, or null if it demands nothing.
     */
    public function requiredLevelFor(string $trigger, ?int $amountMinor = null, ?int $riskCount = null): ?VerificationLevel
    {
        if (! (bool) ($this->config['enabled'] ?? true)) {
            return null;
        }

        /** @var array<string, mixed> $triggers */
        $triggers = (array) ($this->config['triggers'] ?? []);
        $rule = $triggers[$trigger] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        $level = VerificationLevel::tryFrom((string) ($rule['level'] ?? ''));
        if ($level === null) {
            return null;
        }

        if (($rule['always'] ?? false) === true) {
            return $level;
        }

        if (isset($rule['above_minor']) && $amountMinor !== null && $amountMinor > (int) $rule['above_minor']) {
            return $level;
        }

        if (isset($rule['threshold']) && $riskCount !== null && $riskCount >= (int) $rule['threshold']) {
            return $level;
        }

        return null;
    }

    /** @return array<string, mixed> the configured triggers, for the admin surface */
    public function triggers(): array
    {
        return (array) ($this->config['triggers'] ?? []);
    }
}
