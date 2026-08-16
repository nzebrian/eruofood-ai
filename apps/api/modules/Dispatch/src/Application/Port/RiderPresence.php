<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

use DateTimeImmutable;

/**
 * When did we last hear from this rider?
 *
 * ## Why not reuse CandidateSource
 *
 * `CandidateSource::forRider()` answers "may this rider do *this job*?" and
 * needs a `DispatchRequest` to say so. A sweep has no job in hand — it is
 * asking about riders in the abstract — and manufacturing a fake request to
 * satisfy the signature would be the sort of thing that looks harmless until
 * somebody's eligibility decision quietly depends on a placeholder.
 *
 * So this is one question with one answer. It is a read-only port over M25's
 * rider positions: Dispatch does not store heartbeats of its own, because a
 * second source of "where is this rider" would eventually disagree with the
 * first.
 *
 * ## Null is not "gone dark"
 *
 * Null means we have no position on record at all, which is a gap in *our*
 * data — a rider who has never sent one, or one whose record has been purged
 * by retention. It is not evidence that their phone is off, and callers must
 * not treat it as such. Taking work away from a rider because of our own
 * missing data is the wrong direction to fail, and it is the same judgement
 * `AssignmentService::assertStillEligible()` already makes.
 */
interface RiderPresence
{
    /**
     * When this rider's position was last recorded, or null if we have none.
     */
    public function lastSeenAt(string $riderId): ?DateTimeImmutable;
}
