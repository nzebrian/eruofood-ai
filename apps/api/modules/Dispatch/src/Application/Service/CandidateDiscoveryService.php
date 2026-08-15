<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\CandidateSource;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * Find enough eligible riders, searching no wider than necessary.
 *
 * ## Why it widens rather than starting wide
 *
 * Starting at the maximum radius would find the most riders and be the wrong
 * thing to do: every extra candidate costs eligibility work and, once scoring
 * runs, a routed ETA from a paid provider. Searching 15 km to deliver a plate
 * of rice five hundred metres away is how a dispatch engine becomes the most
 * expensive part of an order.
 *
 * So it starts small, and expands only while it has found too few. Each ring
 * re-searches from the centre rather than searching an annulus — simpler, and
 * the near riders were going to be re-evaluated anyway.
 *
 * ## Why it stops
 *
 * Three separate stops, and all three are needed. Without the radius ceiling a
 * search in a quiet area walks outwards until it finds somebody an hour away.
 * Without the pool-size floor it stops at one candidate and has no fallback if
 * that rider declines. Without the iteration guard a misconfigured expansion
 * factor of 1.0 loops for ever.
 */
final readonly class CandidateDiscoveryService
{
    public function __construct(
        private CandidateSource $source,
        private EligibilityService $eligibility,
        private float $initialRadiusMetres,
        private float $maxRadiusMetres,
        private float $expansionFactor,
        private int $minPoolSize,
        private int $maxPoolSize,
        private int $maxRawCandidates,
    ) {
    }

    public function discover(DispatchRequest $request, DateTimeImmutable $now): CandidateDiscovery
    {
        $radius = max(1.0, $this->initialRadiusMetres);
        $ceiling = max($radius, $this->maxRadiusMetres);

        // Bounded regardless of what the expansion factor is set to. A factor
        // of 1.0 would otherwise never reach the ceiling.
        $maxRings = 10;

        $result = new EligibilityResult([], []);
        $rawCount = 0;
        $searched = $radius;

        for ($ring = 0; $ring < $maxRings; $ring++) {
            $searched = $radius;

            $candidates = $this->source->near($request, $radius, $this->maxRawCandidates, $now);
            $rawCount = count($candidates);

            $result = $this->eligibility->run($candidates, $request, $now);

            if ($result->eligibleCount() >= $this->minPoolSize || $radius >= $ceiling) {
                break;
            }

            $next = $radius * max(1.0, $this->expansionFactor);

            if ($next <= $radius) {
                // A factor of 1.0 or less cannot widen the search. Stop rather
                // than repeat the same query until the loop guard trips.
                break;
            }

            $radius = min($next, $ceiling);
        }

        return new CandidateDiscovery(
            // Capped after eligibility, not before: the cap exists to bound
            // scoring cost, and thinning the pool before the rules run would
            // discard eligible riders in favour of ineligible ones that
            // happened to sort earlier.
            array_slice($result->eligible, 0, max(1, $this->maxPoolSize)),
            $result->rejectionBreakdown,
            $rawCount,
            (int) round($searched),
        );
    }
}
