<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;

/**
 * The single place that decides how much of a dispatch record leaves the building.
 *
 * M25 established this pattern for locations and it earned its keep: per-
 * controller shaping is how a private field ends up on a public endpoint one
 * refactor later, and nobody notices because each controller looked fine on its
 * own.
 *
 * ## What never appears in a rider-facing payload
 *
 * **No other rider's identity, position or score.** A rider is told what they
 * were offered and why *they* are being asked; they are not told who else was
 * considered or how they ranked against them. A leaderboard of colleagues is
 * not a thing a delivery app should hand out.
 *
 * **No score breakdown to riders.** It is stored, and operators can read it —
 * that is what makes the engine debuggable — but shipping the weights to every
 * rider's phone invites gaming and tells them nothing they can act on.
 *
 * ## What operators get that riders do not
 *
 * Rejection breakdowns, score internals, rider identities across a pool. All of
 * it behind `dispatch.read`, none of it including a rider's coordinates —
 * operational necessity is not the same as a live map of where staff are, and
 * M25's `GeoPresenter` remains the only thing that renders a position.
 */
final readonly class DispatchPresenter
{
    /**
     * An offer, as the rider it was made to sees it.
     *
     * @return array<string, mixed>
     */
    public function offerForRider(RiderOffer $offer, DateTimeImmutable $now): array
    {
        return [
            'id' => $offer->id(),
            'delivery_id' => $offer->deliveryId(),
            'vehicle_id' => $offer->vehicleId(),
            'state' => $offer->state()->value,
            'expires_at' => $offer->expiresAt()->format(DATE_ATOM),

            // Both the instant and the countdown: the instant so a slow network
            // cannot make the app show more time than there is, the countdown
            // so the app does not have to trust the device clock.
            'seconds_remaining' => $offer->secondsRemaining($now),

            'eta_seconds' => $offer->etaSeconds(),
            'distance_metres' => $offer->distanceMetres(),
            // Deliberately absent: score, score_breakdown, and anything about
            // the other riders who were considered.
        ];
    }

    /**
     * An assignment, as the rider carrying it sees it.
     *
     * @param list<\EruoFood\Dispatch\Domain\Enum\AssignmentState> $nextStates
     * @return array<string, mixed>
     */
    public function assignmentForRider(Assignment $assignment, array $nextStates = []): array
    {
        return [
            'id' => $assignment->id(),
            'delivery_id' => $assignment->deliveryId(),
            'vehicle_id' => $assignment->vehicleId(),
            'state' => $assignment->state()->value,
            'eta_seconds' => $assignment->etaSeconds(),
            'accepted_at' => $assignment->acceptedAt()->format(DATE_ATOM),
            'ended_at' => $assignment->endedAt()?->format(DATE_ATOM),

            // What the app's next button may offer, decided here rather than in
            // the client — a client that computes its own transition table will
            // eventually disagree with the server's.
            'next_states' => array_map(
                static fn ($state): string => $state->value,
                $nextStates,
            ),
        ];
    }

    /**
     * An assignment, as operations sees it.
     *
     * Adds the rider and the request — an operator investigating a late order
     * needs to know who has it — and still no coordinates.
     *
     * @return array<string, mixed>
     */
    public function assignmentForOperator(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id(),
            'request_id' => $assignment->requestId(),
            'offer_id' => $assignment->offerId(),
            'delivery_id' => $assignment->deliveryId(),
            'rider_id' => $assignment->riderId(),
            'vehicle_id' => $assignment->vehicleId(),
            'state' => $assignment->state()->value,
            'is_active' => $assignment->isActive(),
            'eta_seconds' => $assignment->etaSeconds(),
            'ended_reason' => $assignment->endedReason(),
            'accepted_at' => $assignment->acceptedAt()->format(DATE_ATOM),
            'ended_at' => $assignment->endedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * A dispatch request, as operations sees it.
     *
     * @return array<string, mixed>
     */
    public function requestForOperator(DispatchRequest $request, DateTimeImmutable $now): array
    {
        return [
            'id' => $request->id(),
            'delivery_id' => $request->deliveryId(),
            'order_id' => $request->orderId(),
            'vendor_id' => $request->vendorId(),
            'state' => $request->state()->value,
            'required_vehicle_type' => $request->requiredVehicleType()->value,
            'attempt_count' => $request->attemptCount(),
            'max_attempts' => $request->maxAttempts(),
            'assigned_rider_id' => $request->assignedRiderId(),
            'failure_reason' => $request->failureReason()?->value,

            // The number an operator actually wants when a customer rings: how
            // long has this person been waiting?
            'waiting_seconds' => $request->elapsedSeconds($now),
            'expires_at' => $request->expiresAt()->format(DATE_ATOM),
            'has_expired' => $request->hasExpired($now),
            'created_at' => $request->createdAt()->format(DATE_ATOM),

            // Pickup and dropoff are deliberately absent. An operator who needs
            // them reads the delivery, where M25's presenter decides how much
            // precision leaves the building.
        ];
    }

    /**
     * One round of looking, as operations sees it.
     *
     * The breakdown is the whole point: "eleven riders nearby, nine stale
     * locations" is a next action, and "no eligible riders" is not.
     *
     * @return array<string, mixed>
     */
    public function attemptForOperator(DispatchAttempt $attempt): array
    {
        return [
            'attempt_number' => $attempt->attemptNumber(),
            'search_radius_metres' => $attempt->searchRadiusMetres(),
            'raw_candidate_count' => $attempt->rawCandidateCount(),
            'eligible_candidate_count' => $attempt->eligibleCandidateCount(),
            'rejection_breakdown' => $attempt->rejectionBreakdown(),
            'dominant_rejection' => $attempt->dominantRejection()?->value,
            'summary' => $attempt->summary(),
            'offered_rider_id' => $attempt->offeredRiderId(),
            'offered_score' => $attempt->offeredScore(),
            'outcome' => $attempt->outcome()?->value,
            'duration_ms' => $attempt->durationMs(),
            'completed_at' => $attempt->completedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * A vehicle, as its owner or an operator sees it.
     *
     * The same shape for both: there is nothing on a vehicle a rider may not
     * see about their own vehicle, and nothing an operator needs that the rider
     * does not already know.
     *
     * @return array<string, mixed>
     */
    public function vehicle(Vehicle $vehicle, DateTimeImmutable $now): array
    {
        return [
            'id' => $vehicle->id(),
            'rider_id' => $vehicle->riderId(),
            'type' => $vehicle->type()->value,
            'registration_number' => $vehicle->registrationNumber(),
            'make' => $vehicle->make(),
            'model' => $vehicle->model(),
            'colour' => $vehicle->colour(),
            'capacity_kg' => $vehicle->capacityKg(),
            'capacity_litres' => $vehicle->capacityLitres(),
            'status' => $vehicle->status()->value,
            'verification_state' => $vehicle->verificationState()->value,
            'verification_note' => $vehicle->verificationNote(),
            'is_primary' => $vehicle->isPrimary(),

            // The three questions a rider actually asks, answered rather than
            // left to be derived from the raw dates.
            'is_dispatchable' => $vehicle->isDispatchable($now),
            'documents_current' => $vehicle->documentsAreCurrent($now),
            'expires_soon' => $vehicle->expiresWithin($now, 14),

            'insurance_expires_at' => $vehicle->insuranceExpiresAt()?->format(DATE_ATOM),
            'roadworthiness_expires_at' => $vehicle->roadworthinessExpiresAt()?->format(DATE_ATOM),
            'licence_expires_at' => $vehicle->licenceExpiresAt()?->format(DATE_ATOM),
            'verified_at' => $vehicle->verifiedAt()?->format(DATE_ATOM),
        ];
    }
}
