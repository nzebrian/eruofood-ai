<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Flag;

use EruoFood\Shared\Domain\Flag\FeatureFlag;
use EruoFood\Shared\Domain\Flag\FlagDecision;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagReason;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Flag\FlagTarget;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Flag evaluation backed by configuration.
 *
 * Configuration, not a database, because the first requirement of a kill switch
 * is that it works when things are broken. A flag store that needs a healthy
 * database cannot turn off the feature that is overloading the database. Config
 * is read from a file the deploy already has, and an environment override needs
 * no infrastructure at all beyond a restart.
 *
 * A database-backed evaluator can be added later for self-service rollout
 * changes without redeploying; it implements the same interface and should
 * layer *below* the environment override, never above it.
 *
 * ## Evaluation order
 *
 * 1. **Environment override** — absolute, both directions. The incident lever.
 * 2. **Targeted match** — this merchant / country / region is named.
 * 3. **Percentage rollout** — the subject's stable bucket is inside it.
 * 4. **Safe default** — the flag's declared resting state.
 *
 * Anything that throws lands on the safe default and is logged, because a flag
 * lookup must never be the reason a request fails.
 */
final readonly class ConfigFlagEvaluator implements FlagEvaluator
{
    public function __construct(
        private FlagRegistry $registry,
        private Config $config,
    ) {
    }

    public function isEnabled(string $key, ?FlagTarget $target = null): bool
    {
        return $this->explain($key, $target)->enabled;
    }

    public function explain(string $key, ?FlagTarget $target = null): FlagDecision
    {
        // Deliberately outside the try: an unregistered key is a programming
        // error and must surface, not be swallowed into a quiet `false`.
        $flag = $this->registry->get($key);
        $subject = $target ?? FlagTarget::none();

        try {
            return $this->decide($flag, $subject);
        } catch (Throwable $e) {
            Log::error('[feature-flag] Evaluation failed; falling back to the safe default.', [
                'flag' => $flag->key,
                'safe_default' => $flag->safeDefault,
                'exception' => $e->getMessage(),
            ]);

            return FlagDecision::of($flag->key, $flag->safeDefault, FlagReason::StoreUnavailable);
        }
    }

    private function decide(FeatureFlag $flag, FlagTarget $subject): FlagDecision
    {
        $override = $this->environmentOverride($flag);

        if ($override !== null) {
            return FlagDecision::of($flag->key, $override, FlagReason::EnvironmentOverride);
        }

        /** @var array<string, mixed> $rollout */
        $rollout = (array) $this->config->get("flags.rollout.{$flag->key}", []);

        if ($this->matchesTarget($rollout, $subject, $matched)) {
            return FlagDecision::of($flag->key, true, FlagReason::TargetedMatch, $matched);
        }

        $percentage = $rollout['percentage'] ?? null;

        if (is_int($percentage) && $percentage > 0) {
            $bucket = $subject->bucketFor($flag->key);

            if ($bucket === null) {
                // No identity means no stable bucket. Treating that as "inside"
                // would enable the feature for every anonymous and background
                // caller at once — the opposite of a gradual rollout.
                return FlagDecision::of(
                    $flag->key,
                    $flag->safeDefault,
                    FlagReason::SafeDefault,
                    'no subject identity for percentage rollout',
                );
            }

            return $bucket < $percentage
                ? FlagDecision::of($flag->key, true, FlagReason::PercentageRollout, "bucket {$bucket} < {$percentage}")
                : FlagDecision::of($flag->key, false, FlagReason::OutsideRollout, "bucket {$bucket} >= {$percentage}");
        }

        return FlagDecision::of($flag->key, $flag->safeDefault, FlagReason::SafeDefault);
    }

    /**
     * The environment override, or null when unset.
     *
     * Null and false are different answers: an unset variable must fall through
     * to the rollout rules, while an explicit `false` must stop evaluation dead.
     * `env()` returning null for both is exactly the trap this avoids.
     */
    private function environmentOverride(FeatureFlag $flag): ?bool
    {
        $raw = $this->config->get('flags.overrides.'.$flag->key);

        if ($raw === null || $raw === '') {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param array<string, mixed> $rollout
     * @param-out string|null      $matched
     */
    private function matchesTarget(array $rollout, FlagTarget $subject, ?string &$matched = null): bool
    {
        foreach ([
            'merchants' => $subject->merchantId,
            'countries' => $subject->countryCode,
            'regions' => $subject->regionCode,
            'users' => $subject->userId,
        ] as $dimension => $value) {
            if ($value === null) {
                // The caller did not supply this dimension. Unknown never
                // matches — missing context can fail to enable something, but
                // must never accidentally enable it.
                continue;
            }

            /** @var list<string> $allowed */
            $allowed = (array) ($rollout[$dimension] ?? []);

            if (in_array($value, $allowed, true)) {
                $matched = "{$dimension}: {$value}";

                return true;
            }
        }

        $matched = null;

        return false;
    }
}
