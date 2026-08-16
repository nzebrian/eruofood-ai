<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Application\Port\RiderPresence;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The rider whose phone died.
 *
 * ## What M26 already handles, and what it does not
 *
 * M26's `LocationIsFresh` eligibility rule means a rider whose position has gone
 * stale is never *offered* new work. That half is solved, and this service does
 * not touch it.
 *
 * The gap is the *assignment* a dark rider is already holding. An unanswered
 * offer expires on its own deadline — `OfferExpiryService` already does that,
 * and this does not duplicate it. An accepted assignment has no such deadline:
 * nothing expires it, so the delivery quietly stops moving with no signal to
 * anybody until a customer rings.
 *
 * So this sweep asks one question — "has this rider gone dark?" — and hands
 * their delivery to the existing reassignment path so somebody else can take
 * it.
 *
 * ## What it deliberately does not do
 *
 * **It does not write the rider record.** `RiderDirectory` has no write method,
 * on purpose: Dispatch decides who gets offered work and never edits a rider.
 * Marking a rider offline in Marketplace would cross that boundary, and it is
 * not needed — eligibility already refuses them, so the rider row staying
 * `online` is harmless and honest. Their own app puts them back when it
 * reconnects.
 *
 * **It does not touch a rider past pickup.** Once somebody has the customer's
 * food in their bag, a silent phone is an operational incident, not a dispatch
 * decision — `AssignmentState::allowedNext()` refuses reassignment there and
 * this respects that rather than working around it. Those surface to the
 * Control Centre instead.
 *
 * ## Off by default
 *
 * Behind `dispatch.stale_rider_sweep`, which ships disabled. It can also run in
 * report-only mode, so an operator can compare what it *would* release against
 * the live board before letting it act.
 */
final readonly class StaleRiderSweepService
{
    public function __construct(
        private AssignmentRepository $assignments,
        private RiderPresence $presence,
        private ReassignmentService $reassignment,
        private FlagEvaluator $flags,
        private Clock $clock,
        private int $staleAfterSeconds,
    ) {
    }

    /**
     * Release what riders who have gone dark are holding.
     *
     * @param bool $reportOnly count what would be released without releasing it
     * @return array{assignments_reassigned: int, held_past_pickup: int, examined: int}
     */
    public function sweep(bool $reportOnly = false): array
    {
        $result = ['assignments_reassigned' => 0, 'held_past_pickup' => 0, 'examined' => 0];

        if (! $reportOnly && ! $this->flags->isEnabled('dispatch.stale_rider_sweep')) {
            return $result;
        }

        $now = $this->clock->now();

        foreach ($this->assignments->active() as $assignment) {
            $result['examined']++;

            if (! $this->hasGoneDark($assignment->riderId(), $now->getTimestamp())) {
                continue;
            }

            // Past pickup the food is with the rider; this is not ours to undo.
            if (! $assignment->state()->canTransitionTo(AssignmentState::ReassignmentRequired)) {
                $result['held_past_pickup']++;

                Log::warning('[dispatch] A rider holding food has gone dark; operations must intervene.', [
                    'assignment_id' => $assignment->id(),
                    'state' => $assignment->state()->value,
                ]);

                continue;
            }

            if ($reportOnly) {
                $result['assignments_reassigned']++;

                continue;
            }

            try {
                $this->reassignment->reassign($assignment->id(), 'rider heartbeat lost');
                $result['assignments_reassigned']++;
            } catch (Throwable $e) {
                // One rider's reassignment failing must not stop the sweep
                // reaching the next customer's delivery.
                Log::error('[dispatch] Could not reassign a dark rider.', [
                    'assignment_id' => $assignment->id(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Whether we have stopped hearing from this rider.
     *
     * A rider with no position at all is *not* treated as dark. That is the
     * same judgement `AssignmentService::assertStillEligible()` makes: an
     * absent position is a gap in our own data or a network outage, and taking
     * work away from a rider for our outage is the wrong direction to fail.
     */
    private function hasGoneDark(string $riderId, int $nowTimestamp): bool
    {
        $observedAt = $this->presence->lastSeenAt($riderId);

        if ($observedAt === null) {
            return false;
        }

        return ($nowTimestamp - $observedAt->getTimestamp()) > $this->staleAfterSeconds;
    }
}
