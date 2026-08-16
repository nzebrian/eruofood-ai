<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

/**
 * Decides whether a capability is on, for a given subject.
 *
 * Implementations must obey two rules, and both exist because the alternative
 * has caused outages on other platforms:
 *
 * 1. **An unknown flag is an error, not a `false`.** A typo that silently reads
 *    as "disabled" is indistinguishable from a working, disabled feature.
 * 2. **Failure falls back to the safe default.** If the store backing the flag
 *    cannot be read, the answer is {@see FeatureFlag::$safeDefault} — for a
 *    high-risk capability, off. A flag system whose outage enables everything
 *    it guards is worse than not having one.
 */
interface FlagEvaluator
{
    /**
     * @param string $key a registered flag key, e.g. 'dispatch.engine'
     */
    public function isEnabled(string $key, ?FlagTarget $target = null): bool;

    /**
     * The same decision, with the reason attached.
     *
     * For the operator endpoint and for logs: "off because the kill switch is
     * set" and "off because this merchant is not in the rollout" are the same
     * boolean and completely different situations to be in at 3am.
     */
    public function explain(string $key, ?FlagTarget $target = null): FlagDecision;
}
