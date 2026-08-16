<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Risk;

/**
 * The seam a future abuse-detection engine plugs into.
 *
 * ## Why this exists before there is anything behind it
 *
 * M29's Trust Engine is not in scope, and nothing here tries to detect
 * anything. But the *call sites* are the expensive part: checkout, payment
 * initiation, rider acceptance and account creation all have to ask the
 * question, and adding that later means reopening every one of those flows —
 * each of which is now covered by concurrency guarantees somebody would have to
 * re-verify.
 *
 * So the question is asked now and answered with {@see NullRiskEvaluator},
 * which allows everything. When a real implementation arrives it is a container
 * binding, not a change to a single business flow.
 *
 * ## Fail open, always
 *
 * An abuse detector that is down must never stop customers ordering food. Every
 * implementation is required to allow the action when it cannot reach its
 * backing service — the opposite trade-off from a feature flag's kill switch,
 * and for the opposite reason: here the failure mode of being wrong is refusing
 * legitimate business, and the platform absorbs fraud losses far more cheaply
 * than it absorbs an outage.
 */
interface RiskEvaluator
{
    /**
     * Assess an action about to happen.
     *
     * Implementations must not throw. A detector that raises an exception into
     * a checkout is a detector that takes the shop offline.
     */
    public function evaluate(RiskSubject $subject, RiskSignalType $type): RiskAssessment;

    /**
     * Record something that happened, for later analysis.
     *
     * Fire-and-forget by contract: the caller does not wait for it and does not
     * care whether it succeeded. Observing abuse must never be able to fail the
     * thing being observed.
     */
    /** @param array<string, scalar|null> $context identifiers and counts only — never personal data */
    public function observe(RiskSubject $subject, RiskSignalType $type, array $context = []): void;
}
