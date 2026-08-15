<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * Who may be offered this delivery, and — just as importantly — why the rest may not.
 *
 * ## Eligibility runs before scoring, always
 *
 * Scoring an ineligible rider is wasted work at best. At worst it is a routing
 * call to a paid provider for somebody who was never going to be offered the
 * job, multiplied by every rider in the pool, on every dispatch.
 *
 * ## Mandatory rules cannot be switched off
 *
 * Configuration may disable optional rules per market. {@see run()} filters the
 * chain by `isMandatory()` first, so a `dispatch.eligibility` key naming a
 * mandatory rule does nothing at all. This is not defensive style: a flag that
 * disables "is this rider legally allowed to drive" is a flag somebody will
 * eventually set at 2am to clear a backlog, and the only reliable way to stop
 * that is for the flag not to work.
 *
 * ## Every rejection is counted
 *
 * The breakdown is the whole reason this returns a result object rather than a
 * filtered list. "No eligible riders" tells an operator nothing; "eleven
 * nearby: nine stale locations, two expired insurance" tells them whether they
 * have a platform outage or a paperwork backlog.
 */
final readonly class EligibilityService
{
    /**
     * Optional rules that still run at acceptance time.
     *
     * Deliberately short. These are the ones where "no, not any more" is a
     * truthful answer to a rider who has just tapped Accept — a suspension, and
     * a vehicle that cannot carry the load. Everything else optional is about
     * *who to ask*, and taking it back at acceptance would refuse a rider for a
     * reason that has nothing to do with them.
     */
    private const ACCEPTANCE_SAFETY_RULES = ['rider_active', 'vehicle_suitable'];

    /**
     * @param list<EligibilityRule> $rules
     * @param array<string, bool> $optionalRuleSwitches
     */
    public function __construct(
        private array $rules,
        private array $optionalRuleSwitches = [],
    ) {
    }

    /**
     * @param list<RiderCandidate> $candidates
     */
    public function run(array $candidates, DispatchRequest $request, DateTimeImmutable $now): EligibilityResult
    {
        $active = $this->activeRules();

        $eligible = [];
        $breakdown = [];
        $reasonsByRider = [];

        foreach ($candidates as $candidate) {
            $reason = $this->firstObjection($active, $candidate, $request, $now);

            if ($reason === null) {
                $eligible[] = $candidate;

                continue;
            }

            // First objection only. A rider is out once; counting every rule
            // they failed would make the breakdown add up to more riders than
            // exist and mislead whoever reads it.
            $breakdown[$reason->value] = ($breakdown[$reason->value] ?? 0) + 1;
            $reasonsByRider[$candidate->riderId] = $reason;
        }

        return new EligibilityResult($eligible, $breakdown, $reasonsByRider);
    }

    /** Whether one specific rider may be offered this delivery. */
    public function reasonAgainst(
        RiderCandidate $candidate,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): ?RejectionReason {
        return $this->firstObjection($this->activeRules(), $candidate, $request, $now);
    }

    /**
     * The re-check run inside the assignment lock, at the moment of acceptance.
     *
     * ## Why re-check at all
     *
     * Seconds pass between an offer being made and a rider tapping Accept. In
     * that window a vehicle's insurance can lapse, an operator can suspend a
     * rider, M24 can revoke a verification. Eligibility decided at offer time is
     * a statement about the past; this is the one that decides whether somebody
     * legally and safely may do the job *now*. Doing it inside the lock is what
     * makes it meaningful — outside it, the answer could change between the
     * check and the write.
     *
     * ## Why it is a narrower chain than the offer-time one
     *
     * Only the safety and compliance rules run here. The others would be wrong:
     *
     * - **Fairness** must never refuse a rider who was legitimately offered a
     *   job. Fairness decides *who to ask*; taking it back at the moment of
     *   acceptance would mean a rider tapping Accept and being told no for a
     *   reason that has nothing to do with them.
     * - **Already declined** is nonsense here — they are accepting.
     * - **Availability** likewise: a rider tapping Accept is, self-evidently,
     *   available, whatever their status column last said.
     *
     * What remains is the set where the honest answer really is "no, not any
     * more": suspended rider, revoked verification, unverified or lapsed
     * vehicle, and a vehicle that cannot carry this load.
     */
    public function acceptanceReasonAgainst(
        RiderCandidate $candidate,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): ?RejectionReason {
        return $this->firstObjection($this->acceptanceRules(), $candidate, $request, $now);
    }

    /**
     * The safety and compliance rules, mandatory ones always included.
     *
     * @return list<EligibilityRule>
     */
    public function acceptanceRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (EligibilityRule $rule): bool => $rule->isMandatory()
                || in_array($rule->key(), self::ACCEPTANCE_SAFETY_RULES, true),
        ));
    }

    /**
     * The rules in force, mandatory ones included whatever configuration says.
     *
     * @return list<EligibilityRule>
     */
    public function activeRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            fn (EligibilityRule $rule): bool => $rule->isMandatory()
                || ($this->optionalRuleSwitches[$rule->key()] ?? true),
        ));
    }

    /**
     * @param list<EligibilityRule> $rules
     */
    private function firstObjection(
        array $rules,
        RiderCandidate $candidate,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): ?RejectionReason {
        foreach ($rules as $rule) {
            $reason = $rule->evaluate($candidate, $request, $now);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
