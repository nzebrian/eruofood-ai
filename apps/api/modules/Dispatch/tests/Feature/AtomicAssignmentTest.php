<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Dispatch\Domain\Exception\AssignmentConflict;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Dispatch\Domain\Exception\OfferNoLongerAnswerable;
use EruoFood\Dispatch\Domain\Exception\RiderNoLongerEligible;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — a rider taps Accept, and exactly one of them wins.
 *
 * The failure this whole file is shaped around: two riders are offered the same
 * delivery and both accept within the same hundred milliseconds. If both
 * succeed, two riders arrive at one restaurant for one bag of food — the
 * platform pays twice, the merchant is embarrassed, and one rider did the work
 * for nothing.
 *
 * These tests cover the application layers and the database guarantees. They
 * cannot prove locking: `RefreshDatabase` wraps every test in a transaction, so
 * two "concurrent" writes here are the same connection and never contend. That
 * proof comes from `scripts/dispatch_concurrency_validation.php`, which runs
 * real OS processes against PostgreSQL — and the fact that these tests *cannot*
 * establish it is exactly why that script exists.
 */
function assignments(): AssignmentService
{
    return app(AssignmentService::class);
}

function offerRepo(): OfferRepository
{
    return app(OfferRepository::class);
}

function progress(): DeliveryProgressService
{
    return app(DeliveryProgressService::class);
}

function assignmentRepo(): AssignmentRepository
{
    return app(AssignmentRepository::class);
}

/** @return array{userId: string, riderId: string} */
function dispatchRider(): array
{
    $userId = (string) Str::uuid();
    $riderId = (string) Str::uuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $userId,
        'name' => 'Rider '.substr($riderId, 0, 4),
        'phone' => '+234800'.random_int(1_000_000, 9_999_999),
        'vehicle_type' => 'motorbike',
        'status' => 'online',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['userId' => $userId, 'riderId' => $riderId];
}

/**
 * A real Marketplace delivery behind the dispatch request.
 *
 * Dispatch never owns the delivery (M26 decision 1) — it references one. Tests
 * that skipped creating it were exercising a world that cannot exist, and the
 * bridge correctly refused to advance a delivery that was not there.
 */
function seedDelivery(string $deliveryId): void
{
    DB::table('marketplace_deliveries')->insert([
        'id' => $deliveryId,
        'order_id' => (string) Str::uuid(),
        'vendor_id' => (string) Str::uuid(),
        'status' => 'unassigned',
        'fee_minor' => 50_000,
        'currency' => 'NGN',
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'dropoff_lat' => 6.4531,
        'dropoff_lng' => 3.3958,
        'track_points' => json_encode([]),
        'created_at' => now(),
        'version' => 1,
    ]);
}

function liveRequest(?string $deliveryId = null): DispatchRequest
{
    $deliveryId ??= (string) Str::uuid();

    if (! DB::table('marketplace_deliveries')->where('id', $deliveryId)->exists()) {
        seedDelivery($deliveryId);
    }

    $request = DispatchRequest::open(
        id: app(DispatchRequestRepository::class)->nextIdentity(),
        deliveryId: $deliveryId,
        orderId: (string) Str::uuid(),
        vendorId: (string) Str::uuid(),
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: new DateTimeImmutable(),
        maxAttempts: 5,
        timeBudgetSeconds: 600,
    );

    app(DispatchRequestRepository::class)->save($request);

    return $request;
}

function offerTo(DispatchRequest $request, string $riderId, int $ttl = 45): RiderOffer
{
    $offer = RiderOffer::make(
        id: offerRepo()->nextIdentity(),
        requestId: $request->id(),
        riderId: $riderId,
        deliveryId: $request->deliveryId(),
        now: new DateTimeImmutable(),
        ttlSeconds: $ttl,
        score: 0.8,
    );

    offerRepo()->save($offer);

    return $offer;
}

it('assigns the delivery to the rider who accepts', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    $assignment = assignments()->accept($userId, $offer->id());

    expect($assignment->riderId())->toBe($riderId)
        ->and($assignment->state())->toBe(AssignmentState::Accepted)
        ->and($assignment->deliveryId())->toBe($request->deliveryId());

    // And every side of it moved together.
    expect(offerRepo()->find($offer->id())->state())->toBe(OfferState::Accepted)
        ->and(app(DispatchRequestRepository::class)->find($request->id())->assignedRiderId())->toBe($riderId);
});

/**
 * The race, at the application layer.
 */
it('lets only the first of two riders accept the same delivery', function (): void {
    ['userId' => $firstUser, 'riderId' => $first] = dispatchRider();
    ['userId' => $secondUser, 'riderId' => $second] = dispatchRider();

    $request = liveRequest();
    $offerToFirst = offerTo($request, $first);
    $offerToSecond = offerTo($request, $second);

    assignments()->accept($firstUser, $offerToFirst->id());

    expect(fn () => assignments()->accept($secondUser, $offerToSecond->id()))
        ->toThrow(AssignmentConflict::class);

    // Exactly one assignment exists for the delivery, and nothing partial was
    // left behind by the refused attempt.
    expect(DB::table('dispatch_assignments')->where('delivery_id', $request->deliveryId())->count())
        ->toBe(1)
        ->and(assignmentRepo()->activeForRider($second))->toBeNull();
});

/**
 * The last line, tested directly.
 *
 * The application checks above are one refactor away from being bypassed. This
 * one is not, so it is worth proving it fires on its own.
 */
it('refuses a second active assignment for one delivery at the database', function (): void {
    ['riderId' => $first] = dispatchRider();
    ['riderId' => $second] = dispatchRider();
    $deliveryId = (string) Str::uuid();

    $insert = function (string $riderId) use ($deliveryId): void {
        DB::table('dispatch_assignments')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'offer_id' => (string) Str::uuid(),
            'delivery_id' => $deliveryId,
            'rider_id' => $riderId,
            'state' => 'accepted',
            'accepted_at' => now(),
            'updated_at' => now(),
            'version' => 1,
        ]);
    };

    $insert($first);

    // Two riders arriving at one restaurant is the single worst thing this
    // context can do. The index makes it impossible, not merely unlikely.
    expect(fn () => $insert($second))->toThrow(QueryException::class);
});

it('refuses a second active assignment for one rider at the database', function (): void {
    ['riderId' => $riderId] = dispatchRider();

    $insert = function () use ($riderId): void {
        DB::table('dispatch_assignments')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'offer_id' => (string) Str::uuid(),
            'delivery_id' => (string) Str::uuid(),
            'rider_id' => $riderId,
            'state' => 'accepted',
            'accepted_at' => now(),
            'updated_at' => now(),
            'version' => 1,
        ]);
    };

    $insert();

    expect($insert(...))->toThrow(QueryException::class);
});

/**
 * A finished assignment must not block the next one, or a rider delivers once
 * and never works again.
 */
it('frees the rider and the delivery once the assignment ends', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    $assignment = assignments()->accept($userId, $offer->id());

    foreach ([AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup, AssignmentState::PickedUp, AssignmentState::InTransit, AssignmentState::Delivered] as $next) {
        progress()->advance($userId, $assignment->id(), $next);
    }

    expect(assignmentRepo()->activeForRider($riderId))->toBeNull();

    // The same rider takes another job an hour later.
    $second = liveRequest();
    $secondOffer = offerTo($second, $riderId);

    expect(assignments()->accept($userId, $secondOffer->id())->riderId())->toBe($riderId);
});

/*
|------------------------------------------------------------------------------
| Idempotency — a retry is not a second rider.
|------------------------------------------------------------------------------
*/

it('returns the same assignment when a rider double-taps accept', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    $first = assignments()->accept($userId, $offer->id());
    $second = assignments()->accept($userId, $offer->id());

    // A phone retrying on a flaky connection must not produce a conflict, and
    // certainly not two assignments.
    expect($second->id())->toBe($first->id())
        ->and(DB::table('dispatch_assignments')->count())->toBe(1);
});

/*
|------------------------------------------------------------------------------
| Offer lifecycle
|------------------------------------------------------------------------------
*/

it('refuses to accept an expired offer, and says it expired', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();

    // Already past its deadline when it is read.
    $offer = RiderOffer::make(
        id: offerRepo()->nextIdentity(),
        requestId: $request->id(),
        riderId: $riderId,
        deliveryId: $request->deliveryId(),
        now: new DateTimeImmutable('-5 minutes'),
        ttlSeconds: 45,
    );
    offerRepo()->save($offer);

    // "Expired", not "already answered" — a rider tapping Accept a second after
    // the sweep ran deserves the truthful reason.
    expect(fn () => assignments()->accept($userId, $offer->id()))
        ->toThrow(OfferNoLongerAnswerable::class, 'expired');
});

it('refuses to accept an offer that was already declined', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    assignments()->decline($userId, $offer->id(), 'Too far.');

    expect(fn () => assignments()->accept($userId, $offer->id()))
        ->toThrow(OfferNoLongerAnswerable::class, 'already declined');
});

it('refuses to answer an offer made to somebody else', function (): void {
    ['riderId' => $owner] = dispatchRider();
    ['userId' => $stranger] = dispatchRider();

    $request = liveRequest();
    $offer = offerTo($request, $owner);

    // Same refusal whether the offer belongs to someone else or does not exist,
    // so this cannot be used to enumerate live offers.
    expect(fn () => assignments()->accept($stranger, $offer->id()))
        ->toThrow(DispatchNotAuthorized::class);

    expect(fn () => assignments()->decline($stranger, $offer->id()))
        ->toThrow(DispatchNotAuthorized::class);

    expect(offerRepo()->find($offer->id())->state())->toBe(OfferState::Offered);
});

it('withdraws the other live offers when one rider wins', function (): void {
    ['userId' => $winnerUser, 'riderId' => $winner] = dispatchRider();
    ['riderId' => $loser] = dispatchRider();

    $request = liveRequest();
    $winning = offerTo($request, $winner);
    $losing = offerTo($request, $loser);

    assignments()->accept($winnerUser, $winning->id());

    // A rider staring at an offer that was won thirty seconds ago will tap it
    // and be refused, which reads as the app being broken.
    expect(offerRepo()->find($losing->id())->state())->toBe(OfferState::Cancelled)
        ->and(offerRepo()->liveForRider($loser))->toBeNull();
});

it('refuses two live offers to one rider at the database', function (): void {
    ['riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    offerTo($request, $riderId);

    $second = liveRequest();

    // A rider looks at one job at a time, so they cannot accept two and then
    // discover they can only do one.
    expect(fn () => offerTo($second, $riderId))->toThrow(QueryException::class);
});

it('refuses to offer the same request to one rider twice', function (): void {
    ['riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    $offer->decline(new DateTimeImmutable(), 'No thanks.');
    offerRepo()->save($offer);

    // Re-asking somebody who declined wastes their attention and costs the
    // customer another timeout window.
    expect(fn () => offerTo($request, $riderId))->toThrow(QueryException::class);
});

it('excludes riders who declined or timed out from the next round', function (): void {
    ['riderId' => $declined] = dispatchRider();
    ['riderId' => $timedOut] = dispatchRider();
    ['riderId' => $stillOpen] = dispatchRider();

    $request = liveRequest();

    $a = offerTo($request, $declined);
    $a->decline(new DateTimeImmutable(), 'Busy.');
    offerRepo()->save($a);

    $b = offerTo($request, $timedOut);
    $b->expire(new DateTimeImmutable());
    offerRepo()->save($b);

    offerTo($request, $stillOpen);

    $excluded = offerRepo()->declinedRiderIds($request->id());

    sort($excluded);
    $expected = [$declined, $timedOut];
    sort($expected);

    // A rider who did not answer in forty-five seconds will not answer the same
    // offer a minute later; re-offering just spends the customer's budget.
    expect($excluded)->toBe($expected);
});

it('declining frees the rider without failing the request', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    assignments()->decline($userId, $offer->id(), 'Too far.');

    expect(offerRepo()->find($offer->id())->state())->toBe(OfferState::Declined)
        ->and(offerRepo()->liveForRider($riderId))->toBeNull()
        // The request keeps looking. Declining is a normal thing a rider does.
        ->and(app(DispatchRequestRepository::class)->find($request->id())->state()->isTerminal())
        ->toBeFalse();
});

/*
|------------------------------------------------------------------------------
| The Dispatch/Marketplace boundary (M26 decision 1)
|------------------------------------------------------------------------------
*/

it('walks the journey in order and refuses to skip ahead', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $assignment = assignments()->accept($userId, offerTo($request, $riderId)->id());

    progress()->advance($userId, $assignment->id(), AssignmentState::EnRoutePickup);

    expect(fn () => progress()->advance($userId, $assignment->id(), AssignmentState::Delivered))
        ->toThrow(EruoFood\Dispatch\Domain\Exception\DispatchInvalidState::class);
});

it('refuses to let a rider advance somebody else\'s delivery', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    ['userId' => $stranger] = dispatchRider();

    $request = liveRequest();
    $assignment = assignments()->accept($userId, offerTo($request, $riderId)->id());

    expect(fn () => progress()->advance($stranger, $assignment->id(), AssignmentState::EnRoutePickup))
        ->toThrow(DispatchNotAuthorized::class);
});

it('mirrors a Marketplace advance without becoming a second place to drive it', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $assignment = assignments()->accept($userId, offerTo($request, $riderId)->id());

    $loaded = assignmentRepo()->find($assignment->id());
    $now = new DateTimeImmutable();

    expect($loaded->mirrorDeliveryStatus('en_route_pickup', $now))->toBeTrue()
        ->and($loaded->state())->toBe(AssignmentState::EnRoutePickup);

    // A status with no assignment meaning leaves the record alone rather than
    // guessing, and a backwards mirror is refused rather than applied.
    expect($loaded->mirrorDeliveryStatus('unassigned', $now))->toBeFalse()
        ->and($loaded->mirrorDeliveryStatus('accepted', $now))->toBeFalse()
        ->and($loaded->state())->toBe(AssignmentState::EnRoutePickup);
});

it('stops allowing reassignment once the rider has the food', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $assignment = assignments()->accept($userId, offerTo($request, $riderId)->id());

    foreach ([AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup, AssignmentState::PickedUp] as $next) {
        progress()->advance($userId, $assignment->id(), $next);
    }

    $loaded = assignmentRepo()->find($assignment->id());

    // Past pickup this is an operational incident, not a dispatch decision.
    expect(fn () => $loaded->requireReassignment('Rider unreachable.', new DateTimeImmutable()))
        ->toThrow(EruoFood\Dispatch\Domain\Exception\DispatchInvalidState::class);

    expect(fn () => $loaded->cancel('Customer cancelled.', new DateTimeImmutable()))
        ->not->toThrow(Exception::class);
});

it('keeps the assignment record after a rider drops out', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $assignment = assignments()->accept($userId, offerTo($request, $riderId)->id());

    $loaded = assignmentRepo()->find($assignment->id());
    $loaded->requireReassignment('Rider went offline.', new DateTimeImmutable());
    assignmentRepo()->save($loaded);

    // Never deleted: an operator investigating a late order needs to see that a
    // rider was assigned and did not finish.
    expect(DB::table('dispatch_assignments')->where('id', $assignment->id())->exists())->toBeTrue()
        ->and(assignmentRepo()->find($assignment->id())->state())
        ->toBe(AssignmentState::ReassignmentRequired)
        // And the rider is free to take other work.
        ->and(assignmentRepo()->activeForRider($riderId))->toBeNull();
});

it('counts a rider as busy from acceptance, not from setting off', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    assignments()->accept($userId, offerTo($request, $riderId)->id());

    // They have not moved yet, but they cannot take a second job.
    expect(assignmentRepo()->activeCountsFor([$riderId]))->toBe([$riderId => 1]);
});

/*
|------------------------------------------------------------------------------
| Eligibility, re-checked inside the assignment lock.
|
| Seconds pass between an offer being made and a rider tapping Accept. In that
| window a vehicle's insurance can lapse, an operator can suspend a rider, M24
| can revoke a verification. Eligibility decided at offer time is a statement
| about the past; this is the check that decides whether somebody may safely and
| legally do the job now.
|------------------------------------------------------------------------------
*/

/** Give a rider a position so the acceptance-time candidate can be built. */
function locate(string $riderId, string $userId, string $recordedAt = 'now'): void
{
    DB::table('geo_rider_locations')->updateOrInsert(['rider_id' => $riderId], [
        'user_id' => $userId,
        'latitude' => 6.5244,
        'longitude' => 3.3792,
        'accuracy_metres' => 15.0,
        'source' => 'device',
        'recorded_at' => new DateTimeImmutable($recordedAt),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function approveVehicleFor(string $riderId, ?DateTimeImmutable $insuranceExpiresAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('dispatch_vehicles')->insert([
        'id' => $id,
        'rider_id' => $riderId,
        'type' => 'bike',
        'status' => 'active',
        'verification_state' => 'verified',
        'verified_at' => now(),
        'verified_by' => (string) Str::uuid(),
        'insurance_expires_at' => $insuranceExpiresAt,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'version' => 1,
    ]);

    return $id;
}

it('accepts when the rider is still eligible at the moment they tap', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    locate($riderId, $userId);
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    expect(assignments()->accept($userId, $offer->id())->riderId())->toBe($riderId);
});

it('refuses the acceptance if the rider was suspended after the offer was made', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    locate($riderId, $userId);
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    // Between the offer and the tap.
    DB::table('marketplace_riders')->where('id', $riderId)->update(['status' => 'suspended']);

    expect(fn () => assignments()->accept($userId, $offer->id()))
        ->toThrow(RiderNoLongerEligible::class);

    expect(DB::table('dispatch_assignments')->where('delivery_id', $request->deliveryId())->count())->toBe(0)
        // And nothing partial was left behind: the offer is still answerable if
        // the suspension is lifted.
        ->and(offerRepo()->find($offer->id())->state())->toBe(OfferState::Offered);
});

it('refuses the acceptance if the vehicle\'s insurance lapsed after the offer', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    locate($riderId, $userId);
    $vehicleId = approveVehicleFor($riderId, new DateTimeImmutable('+1 hour'));

    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    // The policy runs out while the offer is on their screen.
    DB::table('dispatch_vehicles')->where('id', $vehicleId)
        ->update(['insurance_expires_at' => new DateTimeImmutable('-1 minute')]);

    $thrown = null;

    try {
        assignments()->accept($userId, $offer->id());
    } catch (RiderNoLongerEligible $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        // The rider is told which of the two it was, because renewing a policy
        // and ringing support are different next actions.
        ->and($thrown->reason->isRiderActionable())->toBeTrue()
        ->and(DB::table('dispatch_assignments')->count())->toBe(0);
});

/**
 * A phone that lost signal between the offer and the tap is not a safety
 * problem. Refusing here would take work from riders for a network outage.
 */
it('still accepts when the rider has no position on record', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    expect(assignments()->accept($userId, $offer->id())->riderId())->toBe($riderId);
});

/**
 * The re-check is narrower than the offer-time chain on purpose: refusing a
 * rider at the moment they tap Accept for a fairness reason would be refusing
 * them for something that has nothing to do with them.
 */
it('does not apply fairness or availability rules at acceptance time', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    locate($riderId, $userId);
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    // Offline by their status column, and well past the consecutive cap.
    DB::table('marketplace_riders')->where('id', $riderId)->update(['status' => 'offline']);

    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    // A rider tapping Accept is, self-evidently, available.
    expect(assignments()->accept($userId, $offer->id())->riderId())->toBe($riderId);
});
